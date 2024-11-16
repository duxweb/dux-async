<?php

use Core\App;
use Core\Queue\Queue;
use Core\Queue\Exceptions\QueueException;

function createQueue($driver = 'sqlite', $options = [])
{
  return new Queue($driver, $options);
}

// 基本功能测试
coroutineTest('Registration', function () {
  $queue = createQueue();
  $queue = $queue->worker('test');

  expect($queue->getWorkers())
    ->toHaveKey('test')
    ->and($queue->getWorkers()['test']->getName())->toBe('test');

  // 测试重复创建
  expect(fn() => $queue->worker('test'))
    ->toThrow(QueueException::class, 'Worker test already exists');
});

coroutineTest('Execution', function () {
  $taskExecuted = false;
  $receivedParams = null;

  $queue = createQueue();
  $queue = $queue->worker('test');

  $queue->register('test_task', function ($params) use (&$taskExecuted, &$receivedParams) {
    $taskExecuted = true;
    $receivedParams = $params;
  });

  $params = ['foo' => 'bar'];
  $result = $queue->send('test_task', 'test', $params);

  expect($result)->toBeTrue()
    ->and($queue->size('test'))->toBe(1);

  $message = $queue->pop('test');
  expect($message['data'])->toBe($params);

  // 测试未注册的任务
  expect(fn() => $queue->send('unknown_task', 'test', []))
    ->toThrow(QueueException::class, 'Task unknown_task not registered');

  // 测试不存在的 worker
  expect(fn() => $queue->send('test_task', 'unknown_worker', []))
    ->toThrow(QueueException::class, 'Worker group unknown_worker does not exist');
});

// 高级特性测试
coroutineTest('Delay', function () {
  $queue = createQueue();
  $queue = $queue->worker('test');
  $queue->register('test_task', fn() => null);

  $result = $queue->send('test_task', 'test', ['data' => 'test'], [
    'delay' => 30
  ]);

  expect($result)->toBeTrue();
  expect($queue->pop('test'))->toBeNull(); // 延迟消息不应该立即可见
  expect($queue->size('test'))->toBe(1); // 但消息应该在队列中
});

coroutineTest('Retry', function () {
  $queue = createQueue();
  $queue = $queue->worker('test');
  $queue->register('test_task', fn() => null);

  $now = time();
  $result = $queue->send('test_task', 'test', ['data' => 'test'], [
    'max_attempts' => 3,
    'retry_ttl' => 3600
  ]);

  expect($result)->toBeTrue();
  $message = $queue->pop('test');
  expect($message)
    ->toHaveKey('max_attempts')
    ->and($message['max_attempts'])->toBe(3)
    ->and($message['expire_at'])->toBe($now + 3600);
});

coroutineTest('Redis', function () {
  $queue = createQueue('redis');
  $queue = $queue->worker('test');
  $queue->register('test_task', fn() => null);

  App::redis()->del('queue:test');
  App::redis()->del('queue:test:delayed');

  $result = $queue->send('test_task', 'test', ['data' => 'test']);
  expect($result)->toBeTrue()
    ->and($queue->size('test'))->toBe(1);

  $message = $queue->pop('test');
  expect($message['data'])->toBe(['data' => 'test']);
  expect($queue->size('test'))->toBe(0);
});

coroutineTest('Scheduling', function () {
  $queue = createQueue('redis');
  $queue = $queue->worker('test');
  $queue->register('test_task', fn() => null);

  App::redis()->del('queue:test');
  App::redis()->del('queue:test:delayed');

  $result = $queue->send('test_task', 'test', ['data' => 'test'], [
    'delay' => 1
  ]);

  expect($result)->toBeTrue();
  expect($queue->pop('test'))->toBeNull();
  expect($queue->size('test'))->toBe(1);

  sleep(3);
  $message = $queue->pop('test');
  expect($message)->not->toBeNull()
    ->and($message['data'])->toBe(['data' => 'test']);
  expect($queue->size('test'))->toBe(0);
});
