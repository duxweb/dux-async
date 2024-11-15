<?php

use Core\Cache\Cache;
use Swoole\Coroutine;

coroutineTest('memory cache adapter works correctly', function () {
    $wg = new \Swoole\Coroutine\WaitGroup();
    $wg->add(1);
    
    Coroutine::create(function () use ($wg) {
        try {
            $cache = new Cache('memory', [
                'defaultLifetime' => 60,
                'tableSize' => 1024
            ]);
            
            $adapter = $cache->factory();

            
            
            // 测试设置和获取
            expect($adapter->set('test-key', 'test-value'))->toBeTrue()
                ->and($adapter->get('test-key'))->toBe('test-value');
            
            // 测试删除
            expect($adapter->delete('test-key'))->toBeTrue()
                ->and($adapter->get('test-key'))->toBeNull();
        } finally {
            $adapter->clear();
            $wg->done();
        }
    });
    
    $wg->wait();
});

coroutineTest('file cache adapter works correctly', function () {
  $wg = new \Swoole\Coroutine\WaitGroup();
  $wg->add(1);
  
  Coroutine::create(function () use ($wg) {
    try {
      $cache = new Cache('file', [
          'prefix' => 'test',
        'defaultLifetime' => 60
    ]);
    
    $adapter = $cache->factory();
    
    // 测试设置和获取
    expect($adapter->set('file-key', ['data' => 'test']))->toBeTrue()
        ->and($adapter->get('file-key'))->toBe(['data' => 'test']);
        
    // 测试过期
    $adapter->set('expire-key', 'will-expire', 1);
      sleep(2);
      expect($adapter->get('expire-key'))->toBeNull();
    } finally {
      $adapter->clear();
      $wg->done();
    }
  });
  
  $wg->wait();
});

coroutineTest('redis cache adapter works correctly', function () {
    $wg = new \Swoole\Coroutine\WaitGroup();
    $wg->add(1);
    
    Coroutine::create(function () use ($wg) {
        try {
            $cache = new Cache('redis', [
                'prefix' => 'test',
                'defaultLifetime' => 60
            ]);
            
            $adapter = $cache->factory();
            
            // 测试设置和获取
            expect($adapter->set('test-key', 'test-value'))->toBeTrue()
                ->and($adapter->get('test-key'))->toBe('test-value');
            
            // 测试删除
            expect($adapter->delete('test-key'))->toBeTrue()
                ->and($adapter->get('test-key'))->toBeNull();

        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $adapter->clear();
            $wg->done();
        }
    });
    
    // 设置等待超时时间
    
  $wg->wait();
});

coroutineTest('cache can handle complex data types', function () {
  $wg = new \Swoole\Coroutine\WaitGroup();
  $wg->add(1);
  Coroutine::create(function () use ($wg) {
    try {
    $cache = new Cache('memory');
    $adapter = $cache->factory();
    
    $complexData = [
        'array' => [1, 2, 3],
        'object' => (object)['foo' => 'bar'],
        'nested' => [
            'a' => [1, 2],
            'b' => ['x' => 'y']
        ]
    ];
    
        expect($adapter->set('complex', $complexData))->toBeTrue()
            ->and($adapter->get('complex'))->toEqual($complexData);
    } finally {
        $adapter->clear();
        $wg->done();
    }
  });

  $wg->wait();
});

coroutineTest('cache respects TTL settings', function () {
  $wg = new \Swoole\Coroutine\WaitGroup();
  $wg->add(1);
  Coroutine::create(function () use ($wg) {
    try {
    $cache = new Cache('memory', ['defaultLifetime' => 1]);
    $adapter = $cache->factory();
    
    $adapter->set('ttl-key', 'will-expire');
    expect($adapter->get('ttl-key'))->toBe('will-expire');
    
    sleep(2);
        expect($adapter->get('ttl-key'))->toBeNull();
    } finally {
        $adapter->clear();
        $wg->done();
    }
  });

  $wg->wait();
});