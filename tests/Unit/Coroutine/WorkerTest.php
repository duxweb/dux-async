<?php

use Core\Coroutine\Worker;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

beforeEach(function () {
  if (!Coroutine::getCid()) {
    throw new Exception('Tests must be run in Swoole coroutine environment');
  }
});

coroutineTest('initialize with correct configuration', function () {
  $worker = new Worker([
    'min_workers' => 5,
    'max_workers' => 10,
  ]);

  Coroutine::sleep(1);

  // 验证是否创建了最小数量的工作协程
  expect($worker->getRunning())->toBe(5);
});

coroutineTest('process tasks successfully', function () {
  $worker = new Worker([
    'min_workers' => 2,
    'max_workers' => 10,
  ]);

  $result = new Channel(1);

  $worker->submit(function () use ($result) {
    $result->push('task completed');
  });

  expect($result->pop(1))->toBe('task completed');
});

coroutineTest('handle multiple tasks concurrently', function () {
  $worker = new Worker([
    'min_workers' => 2,
    'max_workers' => 10,
  ]);

  $results = new Channel(3);
  $startTime = microtime(true);

  // 提交多个耗时任务
  for ($i = 0; $i < 3; $i++) {
    $worker->submit(function () use ($results, $i) {
      Coroutine::sleep(0.1);
      $results->push($i);
    });
  }

  // 收集结果
  $collected = [];
  for ($i = 0; $i < 3; $i++) {
    $collected[] = $results->pop(0.5);
  }

  // 验证并发执行（总耗时应该接近单个任务的耗时）
  expect(microtime(true) - $startTime)->toBeLessThan(0.3);
  expect($collected)->toHaveCount(3);
});

coroutineTest('handle errors with custom error handler', function () {
  $errorCaught = false;

  $worker = new Worker(
    [
      'min_workers' => 2,
      'max_workers' => 10,
    ],
    function (\Throwable $e) use (&$errorCaught) {
      $errorCaught = true;
    }
  );

  $worker->submit(function () {
    throw new \Exception('Test error');
  });

  Coroutine::sleep(0.1);
  expect($errorCaught)->toBeTrue();
});

coroutineTest('throw exception in nonblocking mode when pool is full', function () {
  $worker = new Worker([
    'min_workers' => 2,
    'max_workers' => 5,
    'nonblocking' => true
  ]);

  // 提交长时间运行的任务填满工作池
  for ($i = 0; $i < 5; $i++) {
    $worker->submit(function () {
      Coroutine::sleep(5);
    });
  }

  // 在非阻塞模式下，当池满时应该抛出异常
  expect(fn() => $worker->submit(fn() => null))
    ->toThrow(RuntimeException::class);
});

coroutineTest('block in blocking mode when pool is full', function () {
  $worker = new Worker([
    'min_workers' => 2,
    'max_workers' => 5,
    'nonblocking' => false
  ]);

  $result = new Channel(1);
  $startTime = microtime(true);

  // 填满工作池
  for ($i = 0; $i < 5; $i++) {
    $worker->submit(function () {
      Coroutine::sleep(0.2);
    });
  }

  // 提交额外的任务
  Coroutine::create(function () use ($worker, $result) {
    $worker->submit(function () use ($result) {
      $result->push('done');
    });
  });

  // 验证任务最终完成，且经过了阻塞时间
  expect($result->pop(1))->toBe('done');
  expect(microtime(true) - $startTime)->toBeGreaterThan(0.2);
});

coroutineTest('handle batch task submission', function () {
  $worker = new Worker([
    'min_workers' => 2,
    'max_workers' => 10,
  ]);

  $results = new Channel(3);
  $tasks = [
    fn() => $results->push(1),
    fn() => $results->push(2),
    fn() => $results->push(3)
  ];

  $worker->submitBatch($tasks);

  $collected = [];
  for ($i = 0; $i < 3; $i++) {
    $collected[] = $results->pop(0.5);
  }

  expect($collected)->toHaveCount(3)
    ->and($collected)->toContain(1, 2, 3);
});

coroutineTest('throw exception for invalid capacity', function () {
  expect(fn() => new Worker(['min_workers' => 0]))
    ->toThrow(InvalidArgumentException::class);
});

coroutineTest('clean up resources on shutdown', function () {
  $worker = new Worker([
    'min_workers' => 2,
    'max_workers' => 5
  ]);

  // 通过反射获取和设置属性
  $reflection = new ReflectionClass($worker);

  $isRunningProp = $reflection->getProperty('isRunning');
  $isRunningProp->setAccessible(true);

  $taskChannelProp = $reflection->getProperty('taskChannel');
  $taskChannelProp->setAccessible(true);

  // 触发释放
  $releaseProp = $reflection->getMethod('release');
  $releaseProp->setAccessible(true);
  $releaseProp->invoke($worker);

  expect($isRunningProp->getValue($worker))->toBeFalse()
    ->and($taskChannelProp->getValue($worker)->errCode)->toBe(SWOOLE_CHANNEL_CLOSED);
});
