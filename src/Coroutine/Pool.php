<?php

namespace Core\Coroutine;

use Closure;
use Exception;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

class Pool
{
    private Channel $channel;
    private bool $running = true;
    private int $currentSize = 0;
    private array $idleConnections = [];

    public function __construct(
        // 创建连接的回调函数
        private readonly Closure          $callback,
        // 最小连接数
        private readonly int              $minSize = 30,
        // 最大连接数
        private readonly int              $maxSize = 300,
        // 获取连接的超时时间
        private readonly int              $timeOut = 10,
        // 空闲连接的时长
        private readonly int              $idleTime = 60,
        // 日志记录器
        private readonly ?LoggerInterface $logger = null,
        // 是否开启调试模式
        private readonly bool             $debug = false
    )
    {
        $this->channel = new Channel($this->maxSize);
        $this->startHealthCheck();
    }

    public function pull(): void
    {
        for ($i = 0; $i < $this->minSize; $i++) {
            $this->add();
        }
    }

    public function add(): mixed
    {
        if ($this->currentSize >= $this->maxSize) {
            $this->logger?->warning('Max pool size reached');
            return null;
        }

        try {
            $conn = call_user_func($this->callback);
            $this->channel->push($conn);
            $this->currentSize++;
            $this->idleConnections[spl_object_id($conn)] = time(); // 记录连接的空闲时间
            if ($this->debug) {
                $this->logger?->info('Connection added', ['current_size' => $this->currentSize]);
            }
            return $conn;
        } catch (Exception $e) {
            $this->logger?->error('Failed to create connection', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function get(): mixed
    {
        if ($this->debug) {
            $this->logger?->debug('Pool status', ['isEmpty' => $this->channel->isEmpty()]);
        }

        if ($this->channel->isEmpty() && $this->currentSize < $this->maxSize) {
            if ($this->debug) {
                $this->logger?->debug('Adding new connection', ['current_size' => $this->currentSize]);
            }
            return $this->add();
        }

        if ($this->debug) {
            $this->logger?->debug('Getting connection', ['coroutine_id' => Coroutine::getCid()]);
        }
        $conn = $this->channel->pop($this->timeOut * 1000);

        if (!$conn) {
            throw new Exception('Connection pool timeout');
        }

        unset($this->idleConnections[spl_object_id($conn)]); // 移除空闲记录

        if (!$this->validateConnection($conn)) {
            $this->logger?->warning('Invalid connection, creating new one');
            $this->currentSize--;
            return $this->add();
        }

        return $conn;
    }

    public function put($conn): void
    {
        if ($conn === null) {
            $this->logger?->warning('Attempted to release a null connection');
            return;
        }

        if ($this->channel->isFull()) {
            $this->closeConnection($conn);
            $this->currentSize--;
            return;
        }

        if ($this->debug) {
            $this->logger?->debug('Releasing connection', [
                'coroutine_id' => Coroutine::getCid(),
                'pool_size' => $this->channel->length()
            ]);
        }

        $this->idleConnections[spl_object_id($conn)] = time();
        $this->channel->push($conn);
    }

    private function validateConnection($conn): bool
    {
        if (method_exists($conn, 'ping')) {
            return $conn->ping();
        }
        return true;
    }

    private function closeConnection($conn): void
    {
        if (method_exists($conn, 'close')) {
            $conn->close();
        }
    }

    private function startHealthCheck(): void
    {
        Timer::tick(10 * 1000, function () {
            if ($this->debug) {
                $this->logger?->debug('Running health check');
            }
            $currentTime = time();
            foreach ($this->idleConnections as $id => $lastUsed) {
                if ($currentTime - $lastUsed > $this->idleTime && $this->currentSize > $this->minSize) {
                    $this->logger?->info('Closing idle connection', ['connection_id' => $id]);
                    $conn = $this->channel->pop();
                    if ($conn) {
                        $this->closeConnection($conn);
                        unset($this->idleConnections[$id]);
                        $this->currentSize--;
                    }
                }
            }
        });
    }

    public function close(): void
    {
        $this->running = false;
        while (!$this->channel->isEmpty()) {
            $conn = $this->channel->pop();
            $this->closeConnection($conn);
            $this->currentSize--;
        }
        $this->channel->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function getIdleConnections(): array
    {
        return $this->idleConnections;
    }

    public function getCurrentSize(): int
    {
        return $this->currentSize;
    }

    public function getMaxSize(): int
    {
        return $this->maxSize;
    }

    public function getMinSize(): int
    {
        return $this->minSize;
    }

    public function getTimeOut(): int
    {
        return $this->timeOut;
    }

    public function getIdleTime(): int
    {
        return $this->idleTime;
    }

    public function getRunning(): bool
    {
        return $this->running;
    }
}