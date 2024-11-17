<?php

namespace Core\Coroutine;

use Closure;
use Exception;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Channel;
use WeakMap;

class Pool
{

    private WeakMap $connections;
    private Channel $channel;
    private array $activeConnections = [];
    private bool $running = true;
    private bool $isHealthChecking = false;

    public function __construct(
        private readonly Closure          $callback,
        private readonly int              $maxIdle = 30,
        private readonly int              $maxOpen = 200,
        private readonly int              $timeOut = 5,
        private readonly int              $idleTime = 60,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->connections = new WeakMap();
        $this->channel = new Channel($this->maxOpen);
    }

    public function get(): mixed
    {
        $this->healthCheck();

        if ($this->getCurrentSize() >= $this->maxOpen) {
            $conn = $this->channel->pop($this->timeOut);
            if ($conn) {
                unset($this->connections[$conn]);
                $this->activeConnections[spl_object_hash($conn)] = $conn;
                return $conn;
            }
            return null;
        }

        $conn = $this->channel->pop(0.001);
        if ($conn) {
            unset($this->connections[$conn]);
            $this->activeConnections[spl_object_hash($conn)] = $conn;
            return $conn;
        }

        $conn = $this->create();
        if ($conn) {
            $this->activeConnections[spl_object_hash($conn)] = $conn;
            return $conn;
        }

        return null;
    }

    public function put($conn): void
    {
        if (!$conn || !$this->running) {
            $this->closeConnection($conn);
            return;
        }

        $hash = spl_object_hash($conn);
        if (isset($this->connections[$conn])) {
            $this->logger?->warning('Connection already in pool');
            return;
        }

        if ($this->channel->isFull()) {
            $this->closeConnection($conn);
            return;
        }

        unset($this->activeConnections[$hash]);

        if ($this->channel->push($conn)) {
            $this->connections[$conn] = time();
            $this->logger?->debug('Connection returned to pool', [
                'current_size' => $this->getCurrentSize(),
                'idle_count' => $this->channel->length()
            ]);
        }
    }

    private function create(): mixed
    {
        try {
            $conn = call_user_func($this->callback);
            if ($conn) {
                $this->logger?->debug('Created new connection', [
                    'current_size' => $this->getCurrentSize()
                ]);
            }
            return $conn;
        } catch (Exception $e) {
            $this->logger?->error('Failed to create connection', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function closeConnection($conn): void
    {
        if (!$conn) {
            return;
        }
        unset($this->activeConnections[spl_object_hash($conn)]);
    }

    private function healthCheck(): void
    {
        if ($this->isHealthChecking || !$this->running) {
            return;
        }

        try {
            $this->isHealthChecking = true;
            $currentTime = time();
            $maxCheckTime = 0.1;
            $startTime = microtime(true);

            while (microtime(true) - $startTime < $maxCheckTime) {
                if ($this->channel->isEmpty()) {
                    break;
                }

                $conn = $this->channel->pop(0.001);
                if (!$conn) {
                    break;
                }

                $lastUsed = $this->connections[$conn] ?? 0;
                if ($currentTime - $lastUsed > $this->idleTime) {
                    $this->closeConnection($conn);
                    continue;
                }

                if (!$this->channel->push($conn)) {
                    $this->closeConnection($conn);
                }
                break;
            }
        } finally {
            $this->isHealthChecking = false;
        }
    }

    public function getCurrentSize(): int
    {
        return count($this->activeConnections) + $this->channel->length();
    }

    public function close(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        unset($this->channel);
        unset($this->connections);
    }

    public function getStatus(): array
    {
        return [
            'current_size' => $this->getCurrentSize(),
            'idle_connections' => $this->channel->length(),
            'active_connections' => count($this->activeConnections),
            'is_running' => $this->running,
        ];
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable $e) {
            $this->logger?->error('Error during pool destruction', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
