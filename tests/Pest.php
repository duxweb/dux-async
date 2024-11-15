<?php

use Core\App;
use Core\Coroutine\ContextManage;
use Nette\Utils\FileSystem;
use Swoole\Runtime;

use function Swoole\Coroutine\run;

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
  // 忽略特定的警告
  if (in_array($errno, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE])) {
    return true;
  }
  // 其他错误正常处理
  return false;
});

// 初始化框架
App::create(basePath: __DIR__, port: 1337, debug: false);
ContextManage::init();

// 初始化数据库
FileSystem::delete(base_path('database'));
FileSystem::createDir(base_path('database'));
FileSystem::write(base_path('database/test.sqlite'), '');

// 开启一键协程

function coroutineTest(string $name, \Closure $callback)
{
  Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
  return test($name, function () use ($callback) {
    return run(function () use ($callback) {
      return $callback();
    });
  });
}

uses()->beforeAll(function () {})->in('Unit', 'Feature');
