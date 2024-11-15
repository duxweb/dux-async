<?php

declare(strict_types=1);

namespace Core;

use Core\App\Attribute;
use Core\Cache\Cache;
use Core\Config\TomlLoader;
use Core\Coroutine\Timer;
use Core\Coroutine\Worker;
use Core\Database\Migrate;
use Core\Event\Event;
use Core\Lock\Lock;
use Core\Logs\LogHandler;
use Core\Redis\Redis;
use Core\Translation\TomlFileLoader;
use Core\Utils\Fmt;
use Core\Views\Render;
use DI\Container;
use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager;
use Latte\Engine;
use Monolog\Level;
use Monolog\Logger;
use Noodlehaus\Config;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Translation\Translator;
use function Termwind\render;

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
    public static string $version = '0.0.1';
    public static array $registerApp = [];
    public static bool $debug = true;
    public static string $logo = '';
    public static string $lang = '';
    public static string $timezone = '';
    public static function create(string $basePath, string $lang = 'en-US', string $timezone = 'UTC', ?string $host = null, ?int $port = null, ?bool $debug = true, ?string $logo = '')
    {
        self::$logo = $logo;
        self::$lang = $lang;
        self::$debug = $debug;
        self::$timezone = $timezone;

        self::$basePath = $basePath;
        self::$configPath = $basePath . '/config';
        self::$dataPath = $basePath . '/data';
        self::$publicPath = $basePath . '/public';
        self::$appPath = $basePath . '/app';
        $dotenv = Dotenv::createImmutable(self::$basePath);
        $dotenv->safeLoad();

        self::$di = new Container();
        self::$di->set('lang', $lang);
        self::$di->set('timezone', $timezone);
        self::$di->set('port', $port ?? 8900);
        self::$di->set('host', $host ?? '127.0.0.1');
        self::$di->set('debug', $debug);


        self::$bootstrap = new \Core\Bootstrap();
        self::$bootstrap->registerFunc();
        self::$bootstrap->registerConfig();
        self::$bootstrap->registerWeb();
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

    public static function lock(?string $type = null): LockFactory
    {
        $config = self::config("use");
        $type = $type ?? $config->get("lock.type", "memory");

        if (!self::$di->has("lock." . $type)) {
            self::$di->set(
                "lock." . $type,
                Lock::init($type)
            );
        }
        return self::$di->get("lock." . $type);
    }

    public static function cache(?string $type = null): Psr16Cache
    {
        $config = self::config("use");
        $type = $type ?? $config->get("cache.type", "memory");

        if (!self::$di->has("cache." . $type)) {
            self::$di->set(
                "cache." . $type,
                new Cache($type, $config->get("cache", []))
            );
        }
        return self::$di->get("cache." . $type)->factory();
    }

    public static function redis(string $name = "default", int $database = 0): \Predis\ClientInterface|\Redis
    {
        if (!self::$di->has("redis." . $name)) {
            $config = self::config("database")->get("redis.drivers." . $name);
            $redis = new Redis($config);
            self::$di->set(
                "redis." . $name,
                $redis
            );
        }
        $redis = self::$di->get("redis." . $name)->factory();
        $redis->select($database);
        return $redis;
    }

    public static function view(string $name): Engine
    {
        if (!self::$di->has("view." . $name)) {
            self::$di->set(
                "view." . $name,
                Render::init($name)
            );
        }
        return self::$di->get("view." . $name);
    }

    public static function timer(): Timer
    {
        return new Timer();
    }

    public static function worker(): Worker
    {
        if (!self::$di->has("worker")) {
            self::$di->set("worker", new Worker(
                \Core\App::config("use")->get('worker', [
                    'nonblocking' => true,
                    'expiry_duration' => 60,
                    'min_workers' => 10,
                    'max_workers' => 10000,
                ]),
                logger: App::log('worker', App::$debug ? Level::Debug : Level::Info),
            ));
        }
        return self::$di->get("worker");
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

    public static function attributes(): array
    {
        if (self::$di->has("attributes")) {
            return self::$di->get("attributes");
        }

        $attributes = Attribute::load(self::$registerApp);

        self::$di->set("attributes", $attributes);
        return $attributes;
    }

    public static function permission(): Permission\Register
    {
        if (self::$di->has("permission")) {
            return self::$di->get("permission");
        }

        $permission = new Permission\Register();
        self::$di->set("permission", $permission);
        return $permission;
    }

    public static function resource(): Resources\Register
    {
        if (self::$di->has("resource")) {
            return self::$di->get("resource");
        }

        $resource = new Resources\Register();
        self::$di->set("resource", $resource);
        return $resource;
    }

    public static function route(): Route\Register
    {
        if (self::$di->has("route")) {
            return self::$di->get("route");
        }

        $route = new Route\Register();
        self::$di->set("route", $route);
        return $route;
    }

    public static function trans(): Translator
    {
        if (!self::$di->has("trans")) {
            $translator = new Translator(self::$lang);
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

    public static function log(string $app = "app", Level $level = Level::Debug): Logger
    {
        if (!self::$di->has("logger." . $app)) {
            self::$di->set(
                "logger." . $app,
                LogHandler::init($app, $level)
            );
        }
        return self::$di->get("logger." . $app);
    }

    public static function config(string $name): Config
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

    public static function banner()
    {
        $logo = self::$logo ?: null;
        $version = self::$version;
        $host = self::$di->get('host');
        $port = self::$di->get('port');
        $time = date('Y-m-d H:i:s');
        $debug = App::$debug ? 'true' : 'false';

        $swooleVersion = SWOOLE_VERSION;
        $phpVersion = phpversion();
        $pid = getmypid();


        $banner = $logo ? <<<HTML
        <div class="mb-1">
            <pre>{$logo}</pre>
        </div>
        HTML : null;

        render(<<<HTML
            <div class="">
                {$banner}
                <div class="flex">
                    <span class="mr-1">⇨ </span>
                    <span class="mr-1">Version</span>
                    <span class="text-green mr-2">{$version}</span>
                    <span class="mr-1">PHP</span>
                    <span class="text-green mr-2">{$phpVersion}</span>
                    <span class="mr-1">Swoole</span>
                    <span class="text-green mr-2">{$swooleVersion}</span>
                    <span class="mr-1">Debug</span>
                    <span class="text-green mr-2">{$debug}</span>
                    <span class="mr-1">PID</span>
                    <span class="text-green mr-2">{$pid}</span>
                </div>
                <div>
                    <span class="mr-1">⇨ </span>
                    <span>Start time</span>
                    <span class="text-cyan ml-1">{$time}</span>
                </div>
                <div>
                    <span class="mr-1">⇨ </span>
                    <span>Web server</span>
                    <span class="text-cyan ml-1">http://{$host}:{$port}</span>
                </div>
            </div>
        HTML);
    }
}
