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

App::create(basePath: __DIR__, port: 1337, logo: $logoTemplate, debug: true);

App::web()->get('/', function (ServerRequestInterface $request, ResponseInterface $response) {


    // 测试数据库连接池
    for ($i = 0; $i < 10; $i++) {
        App::db()->getConnection()->table('system_user')->first();
    }


    return sendText($response, 'hello');
});

App::run();
