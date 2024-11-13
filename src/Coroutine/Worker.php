<?php

namespace Core\Coroutine;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * 协程工作池
 * 用于管理和复用协程资源，控制并发数量
 */
class Worker
{
    /**
     * 工作协程队列
     * 用于存储可用的工作协程槽位
     */
    private Channel $workerQueue;

    /**
     * 当前正在运行的任务数量
     */
    private int $running = 0;

    /**
     * 工作池是否已关闭
     */
    private bool $closed = false;

    /**
     * 异常处理器
     * 用于处理任务执行过程中的异常
     */
    private ?\Closure $panicHandler = null;

    /**
     * 等待中的任务数量
     * 用于控制任务队列大小
     */
    private int $waitingTasks = 0;

    /**
     * 日志记录器
     */
    private LoggerInterface $logger;

    /**
     * 获取工作协程的超时时间（秒）
     */
    private const POP_TIMEOUT = 0.001;

    /**
     * 默认配置选项
     */
    private const DEFAULT_OPTIONS = [
        'capacity' => 1000,            // 工作池最大容量（最大并发协程数）
        'nonblocking' => false,        // 是否立即返回（true=立即返回，false=等待可用协程）
        'max_queue_size' => 100000,      // 最大等待任务数
        'expiry_duration' => 10,        // 空闲协程清理时间(秒)
        'min_workers' => 10,            // 最小工作协程数
    ];

    /**
     * 当前工作池配置选项
     */
    private array $options;

    /**
     * 构造函数
     *
     * @param array $options 配置选项
     *        - capacity: int 工作池最大容量
     *        - nonblocking: bool 是否为非阻塞模式
     *        - max_queue_size: int 最大等待任务数
     *        - expiry_duration: int 空闲协程清理时间(秒)
     *        - min_workers: int 最小工作协程数
     * @throws \InvalidArgumentException 当容量小于等于0时抛出
     */
    public function __construct(
        array            $options = [],
        ?LoggerInterface $logger = null
    )
    {
        $this->logger = $logger ?? new NullLogger();
        $this->logger->debug('Worker pool initializing', [
            'options' => $options
        ]);

        if ($options['capacity'] <= 0) {
            throw new \InvalidArgumentException('Capacity must be positive');
        }

        $this->options = array_merge(self::DEFAULT_OPTIONS, $options);
        $this->workerQueue = new Channel($this->options['capacity']);

        // 初始化工作队列
        for ($i = 0; $i < $this->options['capacity']; $i++) {
            $this->workerQueue->push(true);
        }

        // 启动清理器
        $this->startCleaner();
    }

    /**
     * 批量提交任务
     */
    public function submitBatch(array $tasks, float $timeout = -1): array
    {
        if ($this->closed) {
            throw new \RuntimeException('Worker pool is closed');
        }

        if ($this->waitingTasks >= $this->options['max_queue_size']) {
            throw new \RuntimeException('Task queue is full');
        }

        $results = [];
        $barrier = Barrier::make();
        $channels = [];

        // 提交所有任务
        foreach ($tasks as $index => [$task, $args]) {
            $taskId = uniqid('task_');

            $resultChan = new Channel(1);
            $channels[$index] = $resultChan;

            Coroutine::create(function () use ($task, $args, $resultChan, &$results, $index, $barrier, $taskId) {
                try {
                    $this->executeTask($taskId, $task, $args, $resultChan);
                    $results[$index] = $resultChan->pop();
                } finally {
                    Barrier::wait($barrier);
                }
            });
        }

        // 设置整体超时
        if ($timeout > 0) {
            Coroutine::create(function () use ($barrier, $timeout, $channels) {
                if (Coroutine::sleep($timeout)) {
                    return;
                }
                foreach ($channels as $chan) {
                    if ($chan->isEmpty()) {
                        $chan->push([
                            'success' => false,
                            'error' => new \RuntimeException('Batch timeout')
                        ]);
                    }
                }
                Barrier::wait($barrier);
            });
        }

        Barrier::wait($barrier);
        return $results;
    }

    /**
     * 提交单个任务
     */
    public function submit(callable $task, float $timeout = 0): mixed
    {
        $taskId = uniqid('task_');
        
        $channel = new Channel(1);

        $this->executeTask($taskId, $task, [], $channel, $timeout);
        $result = $channel->pop();

        return $result['success'] ? $result['result'] : null;
    }


    /**
     * 执行任务的核心方法
     */
    private function runTask(callable $task, array $args): array
    {
        try {
            // 获取工作协程槽位
            if ($this->options['nonblocking'] && $this->workerQueue->isEmpty()) {
                throw new \RuntimeException('Pool overload');
            }

            $worker = $this->workerQueue->pop(self::POP_TIMEOUT);
            if ($worker === false) {
                return [
                    'success' => false,
                    'error' => new \RuntimeException('No available worker')
                ];
            }

            $this->running++;
            try {
                $result = $task(...$args);
                return [
                    'success' => true,
                    'data' => $result
                ];
            } catch (Throwable $e) {
                if ($this->panicHandler) {
                    ($this->panicHandler)($e);
                }
                return [
                    'success' => false,
                    'error' => $e
                ];
            } finally {
                $this->running--;
                $this->workerQueue->push(true);
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => $e
            ];
        }
    }

    /**
     * 执行具体任务（包含超时控制和日志记录）
     */
    private function executeTask(string $taskId, callable $task, array $args, Channel $resultChan, float $timeout = 0): void
    {
        $this->logger->debug('Executing task', [
            'task_id' => $taskId,
            'running_tasks' => $this->running
        ]);

        $cid = Coroutine::create(function () use ($task, $args, $resultChan, $taskId, $timeout) {
            $timer = null;

            try {
                // 设置超时定时器
                if ($timeout > 0) {
                    $timer = \Swoole\Timer::after($timeout * 1000, function () use ($resultChan, $taskId) {
                        $this->logger->warning('Task timeout', ['task_id' => $taskId]);
                        if ($resultChan->isEmpty()) {
                            $resultChan->push([
                                'success' => false,
                                'error' => new \RuntimeException('Task timeout')
                            ]);
                        }
                    });
                }

                // 执行任务
                $result = $this->runTask($task, $args);

                // 清除超时定时器
                if ($timer) {
                    \Swoole\Timer::clear($timer);
                }

                if ($resultChan->isEmpty()) {
                    $resultChan->push($result);
                }
            } finally {
                $this->logger->debug('Task completed', [
                    'task_id' => $taskId,
                    'running_tasks' => $this->running
                ]);
                $resultChan->close();
            }
        });

        if ($cid === false) {
            $this->logger->error('Failed to create coroutine for task', [
                'task_id' => $taskId
            ]);
            $resultChan->push([
                'success' => false,
                'error' => new \RuntimeException('Failed to create coroutine')
            ]);
            $resultChan->close();
        }
    }

    /**
     * 启动空闲协程清理器
     * 定期清理超过最小值的空闲协程
     */
    private function startCleaner(): void
    {
        if ($this->options['expiry_duration'] <= 0) {
            return;
        }

        Coroutine::create(function () {
            while (!$this->closed) {
                Coroutine::sleep($this->options['expiry_duration']);
                $this->cleanIdleWorkers();
            }
        });
    }

    /**
     * 清理空闲的工作协程
     * 保持最小工作协程数，清理多余的空闲协程
     */
    private function cleanIdleWorkers(): void
    {
        if ($this->closed) {
            return;
        }

        $minWorkers = max(
            $this->options['min_workers'],
            $this->running
        );

        $currentFree = $this->workerQueue->length();
        if ($currentFree > $minWorkers) {
            $toClean = min(
                $currentFree - $minWorkers,
                $currentFree - $this->running
            );

            for ($i = 0; $i < $toClean; $i++) {
                $this->workerQueue->pop(0.001);
            }

            // 重新补充到最小值
            $currentSize = $this->workerQueue->length();
            $needAdd = $minWorkers - $currentSize;
            for ($i = 0; $i < $needAdd; $i++) {
                $this->workerQueue->push(true);
            }
        }
    }

    /**
     * 等待所有任务完成
     *
     * @param float $timeout 超时时间(秒)，-1表示永不超时
     * @return bool 是否所有任务都已完成
     */
    public function wait(float $timeout = -1): bool
    {
        if ($this->running <= 0) {
            return true;
        }

        $startTime = microtime(true);
        while ($this->running > 0) {
            if ($timeout > 0 && (microtime(true) - $startTime) > $timeout) {
                return false;
            }
            Coroutine::sleep(0.01);
        }
        return true;
    }

    /**
     * 释放工作池资源
     * 关闭工作池并等待所有任务完成
     *
     * @param float $timeout 等待超时时间(秒)
     * @return bool 是否成功释放所有资源
     */
    public function release(float $timeout = 5.0): bool
    {
        $this->closed = true;
        $result = $this->wait($timeout);

        while (!$this->workerQueue->isEmpty()) {
            $this->workerQueue->pop(0.001);
        }
        $this->workerQueue->close();

        return $result;
    }

    /**
     * 设置异常处理器
     *
     * @param callable $handler 异常处理函数
     */
    public function setPanicHandler(callable $handler): void
    {
        $this->panicHandler = $handler;
    }

    /**
     * 获取当前正在运行的任务数
     *
     * @return int 运行中的任务数
     */
    public function running(): int
    {
        return $this->running;
    }

    /**
     * 检查工作池是否已关闭
     *
     * @return bool 是否已关闭
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }
}