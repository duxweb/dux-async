<?php

declare(strict_types=1);

namespace Core;

use Core\Config\TomlLoader;
use Core\Database\Migrate;
use Core\Event\Event;
use Core\Logs\LogHandler;
use Core\Translation\TomlFileLoader;
use DI\Container;
use Dotenv\Dotenv;
use Dux\Database\Db;
use Illuminate\Database\Capsule\Manager;
use Monolog\Level;
use Monolog\Logger;
use Noodlehaus\Config;
use Swoole\Runtime;
use Symfony\Component\Translation\Translator;

class App
{

    public static string $basePath;
    public static string $configPath;
    public static string $dataPath;
    public static string $publicPath;
    public static string $appPath;
    public static Bootstrap $bootstrap;
    public static Container $di;
    public static array $config;
    public static array $registerApp = [];

    public static function create(string $basePath, int $port, string $lang = 'en-US', string $timezone = 'UTC')
    {
        self::$basePath = $basePath;
        self::$configPath = $basePath . '/config';
        self::$dataPath = $basePath . '/data';
        self::$publicPath = $basePath . '/public';
        self::$appPath = $basePath . '/app';

        $dotenv = Dotenv::createImmutable(self::$basePath);
        $dotenv->safeLoad();

        self::$di = new Container();
        self::$di->set('locale', $lang);
        self::$di->set('timezone', $timezone);
        self::$di->set('port', $port);


        self::$bootstrap = new \Core\Bootstrap();
        self::$bootstrap->registerFunc();
        self::$bootstrap->registerConfig();
        self::$bootstrap->registerWeb();
        self::$bootstrap->registerView();
    }

    public static function run()
    {
        self::$bootstrap->loadApp();
        self::$bootstrap->loadRoute();
        self::$bootstrap->loadCommand();
        self::$bootstrap->run();
    }

    public static function web(): \Slim\App
    {
        return self::$bootstrap->web;
    }

    public static function di(): Container
    {
        return self::$di;
    }

    public static function db(): Manager
    {
        if (!self::$di->has("db")) {
            self::di()->set(
                "db",
                \Core\Database\Db::init(\Core\App::config("database")->get("db.drivers"))
            );
        }
        return self::$di->get("db");
    }

    public static function dbMigrate(): Migrate
    {
        if (!self::$di->has("db.migrate")) {
            self::$di->set(
                "db.migrate",
                new Migrate()
            );
        }
        return self::$di->get("db.migrate");
    }

    public static function event(): Event
    {
        if (self::$di->has("events")) {
            return self::$di->get("events");
        }

        $event = new Event();
        self::di()->set(
            "events",
            $event
        );
        return $event;
    }

    public static function trans(): Translator
    {
        if (!self::$di->has("trans")) {
            $lang = self::$di->get('locale') ?: 'en-US';
            $translator = new Translator($lang);
            $translator->addLoader('toml', new TomlFileLoader());
            self::loadTrans(__DIR__ . '/Langs', $translator);
            self::$di->set(
                "trans",
                $translator
            );
        }
        return self::$di->get("trans");
    }

    public static function loadTrans(string $dirPath, Translator $trans): void
    {
        $files = glob($dirPath . "/*.*.toml");
        if (!$files) {
            return;
        }
        foreach ($files as $file) {
            $names = explode('.', basename($file, '.toml'), 2);
            [$name, $lang] = $names;
            $trans->addResource('toml', $file, $lang, $name);
        }
    }

    public static function log(string $app = "app"): Logger
    {
        if (!self::$di->has("logger." . $app)) {
            self::$di->set(
                "logger." . $app,
                LogHandler::init($app, Level::Debug)
            );
        }
        return self::$di->get("logger." . $app);
    }

    public static function config(string $name)
    {
        if (self::$di->has("config." . $name)) {
            return self::$di->get("config." . $name);
        }

        $file = App::$configPath . "/$name.dev.toml";
        if (!is_file($file)) {
            $file = App::$configPath . "/$name.toml";
        }
        $string = false;

        if (!is_file($file)) {
            $file = '';
            $string = true;
        }
        $config = new Config($file, new TomlLoader(), $string);
        self::$di->set("config." . $name, $config);
        return $config;
    }

}