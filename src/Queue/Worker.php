<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Queue\Exceptions\QueueException;
use Core\Coroutine\Worker as CoroutineWorker;
use Throwable;
use Psr\Log\LoggerInterface;

class Worker
{
  /**
   * @var bool Worker是否在运行
   */
  private bool $running = false;

  /**
   * @var CoroutineWorker 协程工作池
   */
  private CoroutineWorker $pool;

  public function __construct(
    /**
     * @var string 工作名称
     */
    private readonly string $name,
    /**
     * @var Queue 队列
     */
    private readonly Queue $queue,
    /**
     * @var LoggerInterface 日志记录器
     */
    private readonly LoggerInterface $logger,
    /**
     * @var int 最小协程数
     */
    private readonly int $minCoroutines = 5,
    /**
     * @var int 最大协程数
     */
    private readonly int $maxCoroutines = 50,
    /**
     * @var int 健康检查间隔（秒）
     */
    private readonly int $healthCheckInterval = 60
  ) {}

  public function getName(): string
  {
    return $this->name;
  }

  public function getActiveCoroutines(): int
  {
    return $this->pool->getRunning();
  }

  public function isRunning(): bool
  {
    return $this->running;
  }

  public function start(): void
  {
    if ($this->running) {
      return;
    }

    $this->running = true;
    $this->queue->trigger('worker.start', $this->name);

    // 初始化协程池
    $this->pool = new CoroutineWorker([
      'min_workers' => $this->minCoroutines,
      'max_workers' => $this->maxCoroutines,
      'expiry_duration' => $this->healthCheckInterval,
      'nonblocking' => false,
    ], null, $this->logger);

    // 运行队列监控循环
    \Swoole\Coroutine::create(function () {
      while ($this->running) {
        try {
          $message = $this->queue->pop($this->name);

          if ($message !== null) {
            // 将具体任务提交到协程池处理
            $this->pool->submit(function () use ($message) {
              try {
                $this->processMessage($message);
                $this->queue->trigger('success', $this->name, $message);
              } catch (Throwable $e) {
                $this->queue->trigger('failed', $this->name, $message, $e);
              }
            });
          }

          \Swoole\Coroutine::sleep(0.05);
        } catch (Throwable $e) {
          if (!$this->running) {
            break;
          }
          $this->logger->error("Worker error: " . $e->getMessage());
        }
      }
    });
  }

  public function stop(): void
  {
    if (!$this->running) {
      return;
    }

    $this->running = false;
    $this->queue->trigger('worker.stop', $this->name);
    $this->logger->info("Worker {$this->name} is stopped");
    $this->pool->close();
  }

  private function processMessage(mixed $message): void
  {
    if (!$this->validateMessage($message)) {
      throw new QueueException("Invalid message format");
    }

    // 检查是否到达可执行时间
    if (isset($message['available_at']) && time() < $message['available_at']) {
      // 未到执行时间,重新入队
      $this->queue->doPush($this->name, $message);
      return;
    }

    $taskName = $message['task_name'];
    $handler = $this->queue->getTaskHandler($taskName)
      ?? throw new QueueException("Task {$taskName} not registered");

    try {
      $handler($message['data']);
    } catch (Throwable $e) {
      $this->handleProcessError($message, $e);
      throw $e;
    }
  }

  private function validateMessage(mixed $message): bool
  {
    return is_array($message)
      && isset($message['task_name'])
      && isset($message['data'])
      && isset($message['attempts'])
      && isset($message['max_attempts'])
      && isset($message['expire_at'])
      && isset($message['available_at']);
  }

  private function handleProcessError(array $message, Throwable $e): void
  {
    if (!isset($message['attempts'])) {
      return;
    }

    $this->queue->trigger('retrying', $this->name, $message, $e);

    if (!$this->queue->retry($this->name, $message)) {
      $this->queue->trigger('retryFailed', $this->name, $message, $e);
    }
  }
}
