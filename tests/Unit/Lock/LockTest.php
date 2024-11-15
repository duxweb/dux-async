<?php

use Core\Lock\Lock;
use Swoole\Coroutine;
use function Swoole\Coroutine\run;

coroutineTest('Lock can correctly prevent concurrent access', function () {
  // 创建一个共享计数器
  $counter = 0;
  $lockFactory = Lock::init('memory');
  $lock = $lockFactory->createLock('test-lock');

  // 创建多个协程同时访问计数器
  $wg = new Coroutine\WaitGroup();
  $wg->add(10);

  for ($i = 0; $i < 10; $i++) {
    Coroutine::create(function () use ($lock, &$counter, $wg) {
      // 获取锁
      $lock->acquire(true);

      try {
        // 模拟耗时操作
        $temp = $counter;
        Coroutine::sleep(0.1);
        $counter = $temp + 1;
      } finally {
        // 释放锁
        $lock->release();
        $wg->done();
      }
    });
  }

  $wg->wait();
  expect($counter)->toBe(10);
});

coroutineTest('Lock timeout mechanism works properly', function () {
  $lockFactory = Lock::init('memory');
  $lock = $lockFactory->createLock('timeout-lock', 1); // 1秒超时

  // 第一个协程获取锁
  $firstAcquired = false;
  Coroutine::create(function () use ($lock, &$firstAcquired) {
    $firstAcquired = $lock->acquire();
    // 持有锁2秒
    Coroutine::sleep(2);
    $lock->release();
  });

  // 等待第一个协程获取锁
  Coroutine::sleep(0.1);
  expect($firstAcquired)->toBeTrue();

  // 第二个协程尝试获取锁（应该失败，因为超时）
  $secondAcquired = $lock->acquire();
  expect($secondAcquired)->toBeFalse();
});

coroutineTest('Different types of lock storage can be initialized properly', function () {
  $memoryLock = Lock::init('memory');
  expect($memoryLock)->toBeInstanceOf(\Symfony\Component\Lock\LockFactory::class);

  $redisLock = Lock::init('redis');
  expect($redisLock)->toBeInstanceOf(\Symfony\Component\Lock\LockFactory::class);
});

coroutineTest('Initializing invalid lock type should throw exception', function () {
  expect(fn() => Lock::init('invalid'))->toThrow(\Core\Handlers\Exception::class);
});
