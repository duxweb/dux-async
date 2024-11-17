<?php

use Core\Coroutine\Pool;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Swoole\Runtime;

// 首先创建一个测试用的连接类
class TestConnection
{
  public $id;
  public function __construct()
  {
    $this->id = uniqid();
  }
}

coroutineTest('basic', function () {
  $pool = new Pool(
    callback: fn() => new TestConnection(),
    maxIdle: 5,
    maxOpen: 10,
    timeOut: 1,
    idleTime: 5,
  );

  try {
    // 测试获取单个连接
    $conn1 = $pool->get();
    expect($conn1)->toBeInstanceOf(TestConnection::class);
    expect($pool->getCurrentSize())->toBe(1);

    // 归还连接
    $pool->put($conn1);
    expect($pool->getCurrentSize())->toBe(1);

    // 测试并发获取连接
    $connections = [];
    $innerWg = new \Swoole\Coroutine\WaitGroup();

    // 只获取3个连接进行测试
    for ($i = 0; $i < 3; $i++) {
      $innerWg->add(1);
      go(function () use ($pool, &$connections, $innerWg) {
        $conn = $pool->get();
        $connections[] = $conn;
        // 模拟一些操作时间
        \Swoole\Coroutine\System::sleep(0.1);
        $innerWg->done();
      });
    }
    $innerWg->wait();

    // 验证连接数量
    expect($pool->getCurrentSize())->toBe(3);
    expect(count($connections))->toBe(3);

    // 归还所有连接
    foreach ($connections as $conn) {
      $pool->put($conn);
    }

    // 测试连接复用
    $conn2 = $pool->get();
    expect($conn2)->toBeInstanceOf(TestConnection::class);
    expect($pool->getCurrentSize())->toBe(3);

    // 测试连接池状态
    $status = $pool->getStatus();
    expect($status)->toHaveKeys(['current_size', 'idle_connections', 'is_running']);
  } finally {
    expect($pool->getCurrentSize())->toBe(3);
  }
});

coroutineTest('limit', function () {
  $logHandler = new TestHandler();
  $logger = new Logger('test', [$logHandler]);

  $pool = new Pool(
    callback: fn() => new TestConnection(),
    maxIdle: 2,
    maxOpen: 3,
    timeOut: 1,
    logger: $logger
  );

  $connections = [];
  for ($i = 0; $i < 3; $i++) {
    $conn = $pool->get();
    expect($conn)->toBeInstanceOf(TestConnection::class);
    $connections[] = $conn;
  }

  expect($pool->getCurrentSize())->toBe(3);

  $extraConn = $pool->get();
  expect($extraConn)->toBeNull();

  foreach ($connections as $conn) {
    $pool->put($conn);
  }
});

coroutineTest('error', function () {
  $logHandler = new TestHandler();
  $logger = new Logger('test', [$logHandler]);

  $pool = new Pool(
    callback: function () {
      throw new Exception('Connection failed');
      return new TestConnection();
    },
    maxIdle: 2,
    maxOpen: 3,
    timeOut: 1,
    logger: $logger
  );

  $conn = $pool->get();
  expect($conn)->toBeNull();
  expect($pool->getCurrentSize())->toBe(0);
});

coroutineTest('auto', function () {
  $logHandler = new TestHandler();
  $logger = new Logger('test', [$logHandler]);


  $pool = new Pool(
    callback: fn() => new TestConnection(),
    maxIdle: 2,      // 最大空闲连接数设为2
    maxOpen: 5,      // 最大连接数设为5
    timeOut: 1,
    idleTime: 1,     // 空闲时间设为1秒，方便测试
    logger: $logger
  );

  try {
    // 1. 测试扩容
    $connections = [];
    // 创建5个连接（达到maxOpen）
    for ($i = 0; $i < 5; $i++) {
      $conn = $pool->get();
      expect($conn)->toBeInstanceOf(TestConnection::class);
      $connections[] = $conn;
    }
    expect($pool->getCurrentSize())->toBe(5);

    // 2. 测试收缩
    // 归还所有连接
    foreach ($connections as $conn) {
      $pool->put($conn);
    }

    // 等待超过空闲时间
    \Swoole\Coroutine\System::sleep(2);

    // 重新获取一个连接，这时应该触发清理
    $conn = $pool->get();
    expect($conn)->toBeInstanceOf(TestConnection::class);

    // 由于maxIdle=2，清理后连接数应该降到较低水平
    expect($pool->getCurrentSize())->toBeLessThanOrEqual(3);

    // 3. 测试连接数维持在maxIdle左右
    $pool->put($conn);
    \Swoole\Coroutine\System::sleep(2);

    $status = $pool->getStatus();
    expect($status['current_size'])->toBeLessThanOrEqual(3);
    expect($status['idle_connections'])->toBeLessThanOrEqual(2);
  } finally {
    $pool->close();
  }
});
