<?php

use Core\App;
use Illuminate\Database\Capsule\Manager;

use function Swoole\Coroutine\run;

describe('db', function () {

    test('init', function () {
        run(function () {
            $db = App::db();
            expect($db)->toBeInstanceOf(Manager::class);
        });
    });


    test('table', function () {
        run(function () {
            // Start Generation Here
            App::db()->getConnection()->getSchemaBuilder()->create('table', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->timestamps();
            });
            
            expect(true)->toBeTrue();

            \Swoole\Timer::clearAll();
        });
    });


    test('insert', function () {
        run(function () {
            $data = App::db()->getConnection()->table('table')->insert([
                'name' => 'test',
            ]);
            expect($data)->toBeTrue();
        });
    });


    test('query', function () {
        run(function () {
            $data = App::db()->getConnection()->table('table')->get()->toArray();
            expect($data)->toBeArray();
        });
    });

    test('update', function () {
        run(function () {
            App::db()->getConnection()->table('table')->where('id', 1)->update([
                'name' => 'test2',
            ]);
            expect(true)->toBeTrue();
        });
    });

    test('delete', function () {
        run(function () {
            App::db()->getConnection()->table('table')->where('id', 1)->delete();
            expect(true)->toBeTrue();
        });
    });

    test('transaction', function () {
        run(function () {
            App::db()->getConnection()->beginTransaction();
            App::db()->getConnection()->table('table')->insert([
                'name' => 'test',
            ]);
            App::db()->getConnection()->rollBack();
            expect(true)->toBeTrue();
        });
    });
});

