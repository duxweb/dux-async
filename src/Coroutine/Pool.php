<?php

namespace Core\Coroutine;

use Closure;
use Core\App;
use Exception;
use Psr\Log\LoggerInterface;
use Swoole\Atomic;
use Swoole\Coroutine\Channel;
use WeakMap;

class Pool
{
    private WeakMap $connections;
    private Channel $channel;
    private Atomic $currentSize;
    private bool $running = true;
    private ?Timer $timer = null;

    public function __construct(
        private readonly Closure          $callback,
        private readonly int              $maxIdle = 30,
        private readonly int              $maxOpen = 300,
        private readonly int              $timeOut = 10,
        private readonly int              $idleTime = 60,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Closure         $close = null,
    ) {
        $this->connections = new WeakMap();
        $this->channel = new Channel($this->maxOpen);
        $this->currentSize = new Atomic(0);

        // 启动健康检查
        $this->startHealthCheck();

        // 添加协程退出监听
        \Swoole\Coroutine::defer(function () {
            $this->close();
        });
    }

    public function create(): mixed
    {
        if ($this->currentSize->get() >= $this->maxOpen) {
            $this->logger?->warning('Max pool size reached');
            return null;
        }

        try {
            $conn = call_user_func($this->callback);
            if (!$conn) {
                return null;
            }
            $this->logger?->debug('Created new connection', [
                'current_size' => $this->currentSize->get()
            ]);
            $this->currentSize->add(1);
            return $conn;
        } catch (Exception $e) {
            $this->logger?->error('Failed to create connection', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function put($conn): void
    {
        if (!$conn) {
            return;
        }

        if (!$this->running) {
            $this->currentSize->sub(1);
            if ($this->close) {
                try {
                    call_user_func($this->close, $conn);
                } catch (Exception $e) {
                    $this->logger?->error('Failed to close connection', ['error' => $e->getMessage()]);
                }
            }
            return;
        }

        if (isset($this->connections[$conn])) {
            $this->logger?->warning('Connection already in pool', [
                'idle_time' => time() - $this->connections[$conn]
            ]);
            return;
        }

        if ($this->channel->isFull()) {
            $this->currentSize->sub(1);
            if ($this->close) {
                try {
                    call_user_func($this->close, $conn);
                } catch (Exception $e) {
                    $this->logger?->error('Failed to close connection', ['error' => $e->getMessage()]);
                }
            }
            return;
        }

        if ($this->channel->push($conn)) {
            $this->connections[$conn] = time();
            $this->logger?->debug('Connection returned to pool', [
                'current_size' => $this->currentSize->get(),
                'idle_count' => count($this->connections)
            ]);
        } else {
            $this->currentSize->sub(1);
        }
    }

    public function get(): mixed
    {
        $this->logger?->debug('Getting connection', [
            'current_size' => $this->currentSize->get(),
            'idle_count' => count($this->connections)
        ]);

        $conn = null;
        if ($this->channel->isEmpty() && $this->currentSize->get() < $this->maxOpen) {
            $conn = $this->create();
            return $conn;
        }

        $conn = $this->channel->pop($this->timeOut);
        if (!$conn) {
            $conn = $this->create();
            return $conn;
        }

        if ($conn) {
            unset($this->connections[$conn]);
            $this->logger?->debug('Connection retrieved from pool', [
                'current_size' => $this->currentSize->get()
            ]);
        }

        return $conn;
    }

    private function startHealthCheck(): void
    {
        $this->timer = App::timer()->tick(10 * 1000, function () {
            $this->logger?->debug('Health check', [
                'current_size' => $this->currentSize->get(),
                'idle_count' => count($this->connections)
            ]);
            try {
                while ($this->currentSize->get() > $this->maxIdle) {
                    $conn = $this->channel->pop(0.001);
                    if (!$conn) {
                        break;
                    }

                    $lastUsed = $this->connections[$conn] ?? 0;
                    $currentTime = time();

                    if ($currentTime - $lastUsed <= $this->idleTime) {
                        $this->channel->push($conn);
                        break;
                    }

                    unset($this->connections[$conn]);
                    $this->currentSize->sub(1);
                    if ($this->close) {
                        try {
                            call_user_func($this->close, $conn);
                        } catch (Exception $e) {
                            $this->logger?->error(
                                'Failed to close idle connection',
                                ['error' => $e->getMessage()]
                            );
                        }
                    }
                    $this->logger?->debug('Closed idle connection', [
                        'idle_time' => $currentTime - $lastUsed
                    ]);
                }
            } catch (Exception $e) {
                $this->logger?->error('Health check failed', ['error' => $e->getMessage()]);
            }
        });
    }

    public function close(): void
    {
        if (!$this->running || !$this->channel && \Swoole\Coroutine::getCid() !== -1) {
            return;
        }

        $this->running = false;

        try {

            if ($this->timer) {
                $this->timer->clear();
                $this->timer = null;
            }

            $timeout = 0.001;
            while (true) {
                $conn = $this->channel->pop($timeout);
                if (!$conn) {
                    break;
                }
                unset($this->connections[$conn]);
                $this->currentSize->sub(1);

                if ($this->close) {
                    try {
                        call_user_func($this->close, $conn);
                    } catch (Exception $e) {
                        $this->logger?->error(
                            'Failed to close connection during shutdown',
                            ['error' => $e->getMessage()]
                        );
                    }
                }
            }
        } finally {
            $this->currentSize->set(0);
            if ($this->channel) {
                $this->channel->close();
            }
        }
    }

    public function getCurrentSize(): int
    {
        return $this->currentSize->get();
    }

    public function getStatus(): array
    {
        return [
            'current_size' => $this->currentSize,
            'idle_connections' => count($this->connections),
            'channel_length' => $this->channel->length(),
            'is_running' => $this->running,
        ];
    }
}
