<?php

use Core\App;
use Illuminate\Database\Capsule\Manager;
use Swoole\Coroutine;

coroutineTest('init', function () {
    $db = App::db();
    expect($db)->toBeInstanceOf(Manager::class);
});


coroutineTest('table', function () {
    $wg = new \Swoole\Coroutine\WaitGroup();
    $wg->add(1);
    Coroutine::create(function () use ($wg) {
        try {
            // Start Generation Here
            App::db()->getConnection()->getSchemaBuilder()->create('table', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->timestamps();
            });

            expect(true)->toBeTrue();
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $wg->done();
        }
    });
});


coroutineTest('insert', function () {
    $data = App::db()->getConnection()->table('table')->insert([
        'name' => 'test',
    ]);
    expect($data)->toBeTrue();
});


coroutineTest('query', function () {
    $data = App::db()->getConnection()->table('table')->get()->toArray();
    expect($data)->toBeArray();
});

coroutineTest('update', function () {
    App::db()->getConnection()->table('table')->where('id', 1)->update([
        'name' => 'test2',
    ]);
    expect(true)->toBeTrue();
});

coroutineTest('delete', function () {
    App::db()->getConnection()->table('table')->where('id', 1)->delete();
    expect(true)->toBeTrue();
});

coroutineTest('transaction', function () {
    App::db()->getConnection()->beginTransaction();
    App::db()->getConnection()->table('table')->insert([
        'name' => 'test',
    ]);
    App::db()->getConnection()->rollBack();

    $data = App::db()->getConnection()->table('table')->get()->toArray();
    expect($data)->toBeArray();
});

coroutineTest('transaction in same coroutine', function () {
    // 清理数据
    App::db()->getConnection()->table('table')->truncate();

    try {
        // 事务1: 插入后回滚
        App::db()->getConnection()->transaction(function ($db) {
            $db->table('table')->insert(['name' => 'test1']);
            throw new \Exception('Rollback transaction');
        });
    } catch (\Exception $e) {
        // 预期的异常，可以忽略
    }

    // 验证数据已回滚
    $data = App::db()->getConnection()->table('table')->get();
    expect($data)->toHaveCount(0);
});

coroutineTest('transaction isolation between coroutines', function () {
    // 清理数据
    App::db()->getConnection()->table('table')->truncate();

    $wg = new \Swoole\Coroutine\WaitGroup();
    $wg->add(2);

    // 协程1: 事务插入但不提交
    Coroutine::create(function () use ($wg) {
        try {
            App::db()->getConnection()->beginTransaction();
            App::db()->getConnection()->table('table')->insert(['name' => 'test1']);
            sleep(1); // 模拟长事务
            App::db()->getConnection()->rollBack();
        } finally {
            $wg->done();
        }
    });

    // 协程2: 查询数据，需要等待一小段时间确保协程1已开始事务
    Coroutine::create(function () use ($wg) {
        usleep(100000); // 等待100ms
        $data = App::db()->getConnection()->table('table')->get();
        expect($data)->toHaveCount(0); // 验证事务隔离性
        $wg->done();
    });

    $wg->wait();
});
