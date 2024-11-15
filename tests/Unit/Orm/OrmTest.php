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
    expect(true)->toBeTrue();
});
