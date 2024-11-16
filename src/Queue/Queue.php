<?php

declare(strict_types=1);

namespace Core\Queue;

use Core\Queue\Contracts\QueueDriverInterface;
use Core\Queue\Drivers\RedisDriver;
use Core\Queue\Drivers\SqliteDriver;
use Core\Queue\Exceptions\QueueException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Queue
{
  private array $workers = [];

  private array $callbacks = [];

  private bool $running = false;

  private \Swoole\Atomic\Long $pushCounter;

  private array $config = [];

  private array $tasks = [];

  private QueueDriverInterface $driver;

  public function __construct(
    string $driver = 'sqlite',
    array $config = [],
    public LoggerInterface $logger = new NullLogger()
  ) {

    $this->driver = match ($driver) {
      'sqlite' => new SqliteDriver($config),
      'redis' => new RedisDriver($config),
      default => throw new \InvalidArgumentException("Invalid driver: {$driver}")
    };

    $this->pushCounter = new \Swoole\Atomic\Long(0);

    $this->config = $config + [
      'push_limit' => 1000,
      'min_coroutines' => 5,
      'max_coroutines' => 50,
      'scale_threshold' => 0.8,
      'health_check_interval' => 60,
      'max_attempts' => 3,
      'retry_ttl' => 1800,
    ];

    $this->worker('default');
  }

  /**
   * 创建一个新的工作组
   */
  public function worker(
    string $name,
  ): self {
    if (isset($this->workers[$name])) {
      throw new QueueException("Worker {$name} already exists");
    }

    $this->workers[$name] = new Worker(
      name: $name,
      queue: $this,
      logger: $this->logger,
      minCoroutines: $this->config['min_coroutines'],
      maxCoroutines: $this->config['max_coroutines'],
      healthCheckInterval: $this->config['health_check_interval']
    );

    return $this;
  }

  /**
   * 启动队列系统
   */
  public function start(): void
  {
    if ($this->running) {
      throw new QueueException("Queue system is already running");
    }

    if (empty($this->workers)) {
      throw new QueueException("No workers configured");
    }

    $this->running = true;

    $this->logger->info('Queue start');

    // Start all workers
    foreach ($this->workers as $worker) {
      $worker->start();
    }
    $this->trigger('start');

    // Register process exit handler
    $signals = [SIGTERM, SIGINT];
    foreach ($signals as $signal) {
      \Swoole\Process::signal($signal, function () {
        $this->shutdown();
      });
    }
  }

  /**
   * 优雅关闭队列系统
   */
  public function shutdown(): void
  {
    $this->running = false;
    $this->trigger('shutdown');
    $this->logger->info('Queue shutdown');

    foreach ($this->workers as $worker) {
      $worker->stop();
    }
  }

  /**
   * 重试失败的消息
   */
  public function retry(string $workerName, array $message): bool
  {
    // Check retry count and expiration time
    if ($message['attempts'] >= $message['max_attempts']) {
      $this->trigger('maxAttemptsExceeded', $workerName, $message);
      return false;
    }

    if (time() > $message['expire_at']) {
      $this->trigger('messageExpired', $workerName, $message);
      return false;
    }

    $message['attempts']++;
    return $this->driver->push($workerName, $message);
  }

  /**
   * 从指定队列中弹出一条消息
   */
  public function pop(string $workerName): array|null
  {
    return $this->driver->pop($workerName);
  }

  /**
   * 注册事件监听器
   */
  public function on(string $event, callable $callback): self
  {
    $this->callbacks[$event][] = $callback;
    return $this;
  }

  /**
   * 触发事件
   */
  public function trigger(string $event, mixed ...$args): void
  {
    if (!isset($this->callbacks[$event])) {
      return;
    }

    foreach ($this->callbacks[$event] as $callback) {
      $callback(...$args);
    }
  }

  /**
   * 获取指定队列的大小
   */
  public function size(string $workerName): int
  {
    return $this->driver->size($workerName);
  }

  /**
   * 获取所有工作组
   * @return array<string, Worker>
   */
  public function getWorkers(): array
  {
    return $this->workers;
  }

  /**
   * 获取队列状态
   */
  public function status(): array
  {
    $status = [];
    foreach ($this->workers as $name => $worker) {
      $status[$name] = [
        'size' => $this->size($name),
        'active_coroutines' => $worker->getActiveCoroutines(),
        'running' => $worker->isRunning()
      ];
    }
    return $status;
  }

  public function getWorkerNumber(): int
  {
    return count($this->workers);
  }

  /**
   * 注册任务处理器
   */
  public function register(string $taskName, callable $handler): self
  {
    if (isset($this->tasks[$taskName])) {
      throw new QueueException("Task {$taskName} already registered");
    }

    $this->tasks[$taskName] = $handler;
    return $this;
  }

  /**
   * 发送任务到指定的工作组
   * 
   * @param string $taskName 任务名称
   * @param string $workerName 工作组名称
   * @param array $params 任务参数
   * @param array $options 任务选项（max_attempts：最大重试次数, retry_ttl：重试超时时间, delay：延迟执行时间）
   */
  public function send(
    string $taskName,
    string $workerName,
    array $params = [],
    array $options = []
  ): bool {

    if (!isset($this->tasks[$taskName])) {
      throw new QueueException("Task {$taskName} not registered");
    }

    if (!isset($this->workers[$workerName])) {
      throw new QueueException("Worker group {$workerName} does not exist");
    }

    $now = time();
    $delay = $options['delay'] ?? 0;

    $message = [
      'task_name' => $taskName,
      'data' => $params,
      'attempts' => 0,
      'max_attempts' => $options['max_attempts'] ?? $this->config['max_attempts'],
      'expire_at' => $now + ($options['retry_ttl'] ?? $this->config['retry_ttl']),
      'created_at' => $now,
      'available_at' => $now + $delay
    ];

    return $this->doPush($workerName, $message);
  }

  public function doPush(string $workerName, array $message): bool
  {
    if ($this->pushCounter->get() >= $this->config['push_limit']) {
      throw new QueueException("Push limit exceeded");
    }

    $this->pushCounter->add(1);

    try {
      $this->trigger('beforePush', $workerName, $message);
      $result = $this->driver->push($workerName, $message);
      $this->trigger('afterPush', $workerName, $message, $result);

      if (!$result) {
        $this->pushCounter->sub(1);
      }

      return $result;
    } catch (\Throwable $e) {
      $this->pushCounter->sub(1);
      throw $e;
    }
  }

  /**
   * 获取任务处理器
   */
  public function getTaskHandler(string $taskName): ?callable
  {
    return $this->tasks[$taskName] ?? null;
  }

  public function getPushCounter(): int
  {
    return $this->pushCounter->get();
  }
}
