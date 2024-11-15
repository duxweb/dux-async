<?php

namespace Core\Coroutine;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class Timer
{
    private Channel $channel;
    private bool $running = true;
    private ?int $parentId;

    public function __construct()
    {
        if (!Coroutine::getCid()) {
            throw new \RuntimeException('Timer must be created in a coroutine context');
        }

        $this->channel = new Channel(1);
        $this->parentId = Coroutine::getPcid();
    }

    public function tick(int $milliseconds, callable $callback): void
    {
        if ($milliseconds < 1) {
            throw new \InvalidArgumentException('Timer interval must be greater than 0');
        }

        Coroutine::create(function () use ($milliseconds, $callback) {
            try {
                if ($this->parentId) {
                    Coroutine::create(function () {
                        Coroutine::join([$this->parentId]);
                        $this->clear();
                    });
                }

                if ($this->channel->push(1, $milliseconds / 1000) === false) {
                    return;
                }

                while ($this->running) {
                    $startTimeMs = microtime(true) * 1000;

                    $callback();

                    $endTimeMs = microtime(true) * 1000;
                    $elapsedMs = $endTimeMs - $startTimeMs;
                    $waitMs = max(0, $milliseconds - $elapsedMs);

                    if ($this->channel->push(1, $waitMs / 1000) === false) {
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                $this->clear();
                throw $e;
            }
        });
    }

    public function after(int $milliseconds, callable $callback): void
    {
        if ($milliseconds < 1) {
            throw new \InvalidArgumentException('Timer interval must be greater than 0');
        }

        Coroutine::create(function () use ($milliseconds, $callback) {
            try {
                if ($this->parentId) {
                    Coroutine::create(function () {
                        Coroutine::join([$this->parentId]);
                        $this->clear();
                    });
                }

                // 使用 Channel 等待指定时间
                $result = $this->channel->pop($milliseconds / 1000);

                // 超时未被中断，执行回调
                if ($result === false && $this->running) {
                    $callback();
                }
            } catch (\Throwable $e) {
                $this->clear();
                throw $e;
            } finally {
                $this->clear();
            }
        });
    }

    public function stop(): void
    {
        $this->channel->close();
    }

    public function clear(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        try {
            $this->channel->push(1, 0.001);
        } catch (\Throwable) {
        }

        try {
            $this->channel->close();
        } catch (\Throwable) {
        }
    }

    public function __destruct()
    {
        $this->clear();
    }
}
