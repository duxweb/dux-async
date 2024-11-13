<?php
namespace Tests;

use Core\App;
use Nette\Utils\FileSystem;

ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_WARNING);

// 初始化框架
App::create(__DIR__, 1337);

// 初始化数据库
FileSystem::delete(base_path('database'));
FileSystem::createDir(base_path('database'));
FileSystem::write(base_path('database/test.sqlite'), '');