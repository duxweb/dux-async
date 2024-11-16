<?php

namespace Core\Coroutine;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Throwable;

/**
 * 协程工作池
 */
class Worker
{

    /**
     * 日志记录器
     */
    private LoggerInterface $logger;

    /**
     * 异常处理器
     */
    private ?\Closure $errorHandler;

    /**
     * 获取工作协程的超时时间（秒）
     */
    private const POP_TIMEOUT = 0.001;

    /**
     * 默认配置选项
     */
    private const DEFAULT_OPTIONS = [
        'nonblocking' => false,        // 非阻塞模式，忽略并发限制
        'expiry_duration' => 60,       // 空闲协程清理时间(秒)
        'min_workers' => 10,           // 最小工作协程数
        'max_workers' => 1000,         // 最大工作协程数
    ];

    /**
     * 当前工作池配置选项
     */
    private array $options;

    /**
     * 任务通道
     */
    private Channel $taskChannel;

    /**
     * 空闲工作协程通道
     */
    private Channel $idleChannel;

    /**
     * 工作协程数量
     */
    private int $running = 0;

    /**
     * 是否正在运行
     */
    private bool $isRunning = false;

    public function __construct(
        array            $options = [],
        ?\Closure $errorHandler = null, // 异常处理器
        ?LoggerInterface $logger = null, // 日志记录器
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->errorHandler = $errorHandler;
        $this->logger->debug('Worker pool initializing', [
            'options' => $options
        ]);

        if ($options['min_workers'] <= 0) {
            throw new \InvalidArgumentException('min_workers must be positive');
        }

        $this->options = array_merge(self::DEFAULT_OPTIONS, $options);

        // 初始化通道
        $this->taskChannel = new Channel(0); // 无限容量
        $this->idleChannel = new Channel($this->options['max_workers']);

        // 启动最小数量的工作协程
        $this->spawnWorkers($this->options['min_workers']);
    }

    /**
     * 提交任务到协程池
     * @throws RuntimeException 当协程池未运行或在非阻塞模式下池已满时
     */
    public function submit(callable $task): void
    {
        if (!$this->isRunning) {
            throw new \RuntimeException('Worker pool is not running');
        }

        $this->taskChannel->push($task);

        $this->checkAndScale();

        // 检查是否达到最大工作协程数且任务通道已满
        if (
            $this->options['nonblocking']
            && $this->running >= $this->options['max_workers']
            && $this->taskChannel->length() > 0
        ) {
            $this->logger->error('Worker pool is full', [
                'running' => $this->running,
                'max_workers' => $this->options['max_workers'],
                'task_channel_length' => $this->taskChannel->length()
            ]);
            throw new \RuntimeException('Worker pool is full');
        }
    }

    /**
     * 批量提交任务
     * @throws RuntimeException 当协程池未运行或在非阻塞模式下池已满时
     */
    public function submitBatch(array $tasks): void
    {
        foreach ($tasks as $task) {
            $this->submit($task);
        }
    }

    /**
     * 检查并扩展工作协程数量
     */
    private function checkAndScale(): void
    {
        // 获取待处理任务数量
        $pendingTasks = $this->taskChannel->length();
        $currentWorkers = $this->running;

        // 如果待处理任务数量大于当前工作协程数，且未达到最大限制，则扩容
        if ($pendingTasks > 0 && $currentWorkers < $this->options['max_workers']) {
            $newWorkers = min(
                $pendingTasks,
                $this->options['max_workers'] - $currentWorkers
            );

            if ($newWorkers > 0) {
                $this->spawnWorkers($newWorkers);
                // 等待新协程创建完成
                Coroutine::sleep(0.01);
            }
        }
    }

    /**
     * 创建工作协程
     */
    private function spawnWorkers(int $count): void
    {
        $this->isRunning = true;
        for ($i = 0; $i < $count; $i++) {
            Coroutine::create(function () {
                $this->running++;
                $lastActiveTime = time();

                while ($this->isRunning) {
                    try {
                        // 尝试获取任务
                        $task = $this->taskChannel->pop(self::POP_TIMEOUT);

                        if ($task === false) {
                            // 检查空闲超时
                            if (
                                time() - $lastActiveTime > $this->options['expiry_duration']
                                && $this->running > $this->options['min_workers']
                            ) {
                                break;
                            }
                            continue;
                        }

                        $lastActiveTime = time();

                        // 执行任务
                        try {
                            $task();
                        } catch (Throwable $e) {
                            if ($this->errorHandler) {
                                ($this->errorHandler)($e);
                            } else {
                                $this->logger->error('Task execution failed', [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString()
                                ]);
                            }
                        }
                    } catch (Throwable $e) {
                        $this->logger->error('Worker error', [
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $this->running--;
            });
        }
    }

    /**
     * 释放资源
     */
    public function close(): void
    {
        $this->isRunning = false;
        $this->taskChannel->close();
        $this->idleChannel->close();
    }

    public function getRunning(): int
    {
        return $this->running;
    }

    public function __destruct()
    {
        if ($this->isRunning) {
            $this->close();
        }
    }
}
