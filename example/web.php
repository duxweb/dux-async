<?php
declare(strict_types=1);

$file = __DIR__ . '/../vendor/autoload.php';

if (!is_file($file)) {
    exit('Please run "composer intall" to install the dependencies, Composer is not installed, please install <a href="https://getcomposer.org/" target="_blank">Composer</a>.');
}

require $file;


use Core\App;
use Core\Coroutine\Worker;
use Core\Func\Fmt;
use Core\Utils\Fmt as UtilsFmt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Coroutine\System;

$logoTemplate = <<<'ASCII'
   ____ __                   ____   __        _      __                
  / __// /  ___ _ ___  ___ _/_  /  / /  __ __| | /| / /___ _ ___  ___ _
 _\ \ / _ \/ _ `// _ \/ _ `/ / /_ / _ \/ // /| |/ |/ // _ `// _ \/ _ `/
/___//_//_/\_,_//_//_/\_, / /___//_//_/\_,_/ |__/|__/ \_,_//_//_/\_, / 
                     /___/                                      /___/        
ASCII;

App::create(basePath: __DIR__, port: 1337, logo: $logoTemplate);

// hello world
App::web()->get('/', function ($request,  $response, array $args) {


    // 测试数据库连接池
    for ($i = 0; $i < 1; $i++) {
        //App::db()->getConnection()->table('system_user')->first();
    }

    // 测试事务
    // App::db()->getConnection()->beginTransaction();
    // App::db()->getConnection()->table('system_user')->insert(['username' => 'test2', 'nickname' => 'test', 'password' => 'test', 'status' => 0]);
    // App::db()->getConnection()->commit();

    // 测试协程池
    // App::worker()->submit(function () {
    //     $executionId = uniqid();
    //     UtilsFmt::Println("hello - {$executionId}");
    //     return '222';
    // });

    // 测试redis
    // App::redis()->set('test', 'test');
    // UtilsFmt::Println(App::redis()->get('test'));


    // 测试cache
    //App::cache()->set('test', 'test', 30);

    // 测试lock
    $lock = App::lock()->createLock('test', 30);
    $lock->acquire();

    sleep(10);

    $lock->release();

    UtilsFmt::Println('解锁了');

    return sendText($response, 'ww');
});


App::web()->get('/cache', function ($request,  $response, array $args) {


    // 测试lock 
    $lock = App::lock()->createLock('test', 30);
    if (!$lock->acquire(true)) {
        UtilsFmt::Println('没有拿到锁');
        return sendText($response, '没有拿到锁');
    }
    $lock->release();
    UtilsFmt::Println('拿到锁');

    //UtilsFmt::Println(App::cache()->get('test'));
});



App::web()->get('/test', function ($request,  $response, array $args) {

    return sendText($response, 'test');
});

App::web()->post('/upload', function (ServerRequestInterface $request, ResponseInterface $response, array $args) {

    print_r($request->getUploadedFiles());

    return sendText($response, 'hello');
});

App::run();