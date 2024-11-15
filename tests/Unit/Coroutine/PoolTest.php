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

coroutineTest('Basic Pool Operations', function () {
  $wg = new \Swoole\Coroutine\WaitGroup();
  $wg->add(1);

  \Swoole\Coroutine::create(function () use ($wg) {
    $closedConnections = [];
    $pool = new Pool(
      callback: fn() => new TestConnection(),
      maxIdle: 5,
      maxOpen: 10,
      timeOut: 1,
      idleTime: 5,
      close: function ($conn) use (&$closedConnections) {
        $closedConnections[] = $conn->id;
      }
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
      expect($status)->toHaveKeys(['current_size', 'idle_connections', 'channel_length', 'is_running']);
    } finally {
      // 关闭连接池并验证
      $pool->close();
      expect($pool->getCurrentSize())->toBe(0);

      // 验证关闭的连接
      expect($closedConnections)->not->toBeEmpty();
    }

    $wg->done();
  });

  // 等待协程完成
  $wg->wait();
});

coroutineTest('Pool Maximum Connection Limit', function () {
  $logHandler = new TestHandler();
  $logger = new Logger('test', [$logHandler]);

  $wg = new \Swoole\Coroutine\WaitGroup();
  $wg->add();

  \Swoole\Coroutine::create(function () use ($logger, $wg) {
    $pool = new Pool(
      callback: fn() => new TestConnection(),
      maxIdle: 2,
      maxOpen: 3,
      timeOut: 1,
      logger: $logger
    );

    try {
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
    } finally {
      $pool->close();
    }
    $wg->done();
  });

  $wg->wait();
});

coroutineTest('Pool Error Handling', function () {
  $logHandler = new TestHandler();
  $logger = new Logger('test', [$logHandler]);

  $wg = new \Swoole\Coroutine\WaitGroup();
  $wg->add();

  go(function () use ($logger, $wg) {
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

    try {
      $conn = $pool->get();
      expect($conn)->toBeNull();
      expect($pool->getCurrentSize())->toBe(0);
    } finally {
      $pool->close();
    }
    $wg->done();
  });

  $wg->wait();
});
