<?php

use Core\Coroutine\Timer;
use Swoole\Coroutine;

coroutineTest('tick', function () {
  $wg = new Coroutine\WaitGroup();
  $wg->add(1);

  Coroutine::create(function () use ($wg) {
    try {
      $timer = new Timer();
      $counter = 0;

      // 每100ms执行一次，共执行3次
      $timer->tick(100, function () use (&$counter, $timer) {
        $counter++;
        if ($counter >= 3) {
          $timer->clear();
        }
      });

      // 等待足够的时间让计时器执行
      Coroutine::sleep(0.5);

      // 只验证执行次数
      expect($counter)->toBe(3);
    } finally {
      $wg->done();
    }
  });

  $wg->wait();
});

coroutineTest('after', function () {
  $wg = new Coroutine\WaitGroup();
  $wg->add(1);

  Coroutine::create(function () use ($wg) {
    try {
      $timer = new Timer();
      $executed = false;
      $startTime = hrtime(true);

      // 200ms后执行一次
      $timer->after(200, function () use (&$executed) {
        $executed = true;
      });
      // 等待 100ms 检查是否还未执行
      Coroutine::sleep(0.01);
      expect($executed)->toBeFalse();
      // 再等待直到超过预期执行时间
      Coroutine::sleep(0.2);
      expect($executed)->toBeTrue();


      // 计算总执行时间（纳秒转毫秒）
      $elapsedMs = (hrtime(true) - $startTime) / 1_000_000;
      expect($elapsedMs)->toBeGreaterThan(200)
        ->and($elapsedMs)->toBeLessThan(400);
    } finally {
      $wg->done();
    }
  });

  $wg->wait();
});

coroutineTest('clear', function () {
  $wg = new Coroutine\WaitGroup();
  $wg->add(1);

  Coroutine::create(function () use ($wg) {
    try {
      $timer = new Timer();
      $counter = 0;

      // 每100ms执行一次
      $timer->tick(100, function () use (&$counter) {
        $counter++;
      });

      // 等待确保至少执行一次
      Coroutine::sleep(0.15);

      // 验证定时器确实执行了
      expect($counter)->toBeGreaterThan(0);

      // 记录当前计数
      $counterBeforeClear = $counter;

      // 清除定时器
      $timer->clear();

      // 再等待一段时间
      Coroutine::sleep(0.2);

      // 验证计数没有继续增加
      expect($counter)->toBe($counterBeforeClear);
    } finally {
      $wg->done();
    }
  });

  $wg->wait();
});

coroutineTest('invalid', function () {
  $wg = new Coroutine\WaitGroup();
  $wg->add(1);

  Coroutine::create(function () use ($wg) {
    try {
      $timer = new Timer();

      expect(fn() => $timer->tick(0, fn() => null))
        ->toThrow(InvalidArgumentException::class);

      expect(fn() => $timer->after(0, fn() => null))
        ->toThrow(InvalidArgumentException::class);
    } finally {
      $wg->done();
    }
  });

  $wg->wait();
});
