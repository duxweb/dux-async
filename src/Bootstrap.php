<?php
declare(strict_types=1);

namespace Core;

use Carbon\Carbon;
use Core\App\Attribute;
use Core\Command\Command;
use Core\Database\BackupCommand;
use Core\Database\RestoreCommand;
use Core\Middleware\StaticMiddleware;
use Core\Utils\Fmt;
use Core\View\View;
use Core\Web\WebCommand;
use DI\DependencyException;
use DI\NotFoundException;
use Latte\Engine;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Symfony\Component\Console\Application;

class Bootstrap
{
    public \Slim\App $web;
    public Engine $view;
    public Resources\Register $resource;
    public Route\Register $route;
    public Application $command;

    public function __construct()
    {
        error_reporting(E_ALL ^ E_DEPRECATED ^ E_WARNING);
    }

    /**
     * 注册公共函数
     * @return void
     */
    public function registerFunc(): void
    {
        Fmt::init();
        require_once "Func/Response.php";
        require_once "Func/Common.php";
    }

    /**
     * 注册公共配置
     * @return void
     */
    public function registerConfig(): void
    {
        date_default_timezone_set(App::di()->get('timezone'));
        Carbon::setLocale(App::di()->get('locale'));
    }


    /**
     * 注册视图服务
     * @return void
     */
    public function registerView(): void
    {
        $this->view = View::init("app");
    }

    /**
     * 注册公共web服务
     * @return void
     */
    public function registerWeb(): void
    {
        // 注册web服务
        AppFactory::setContainer(App::di());
        $this->web = AppFactory::create();

        // 注册资源路由
        $this->resource = new \Core\Resources\Register();
        $this->route = new \Core\Route\Register();
    }


    /**
     * 加载路由
     * @return void
     */
    public function loadRoute(): void
    {
        // 解析内容中间件
        $this->web->addBodyParsingMiddleware();
        // 注册路由中间件
        $this->web->addRoutingMiddleware();
        // 注册异常中间件
        $this->web->addErrorMiddleware(true, false, false);

        $this->web->addMiddleware(new StaticMiddleware);
    }


    /**
     * 加载应用
     * @return void
     * @throws DependencyException
     * @throws NotFoundException
     */
    public function loadApp(): void
    {
        // 全局应用注册
        $appList = App::config("app")->get("registers", []);
        foreach ($appList as $vo) {
            App::$registerApp[] = $vo;
        }

        // 全局注解加载
        App::di()->set("attributes", Attribute::load(App::$registerApp));

        // 事件注解加载
        App::event()->registerAttribute();

        // 应用初始化触发
        foreach ($appList as $vo) {
            call_user_func([new $vo, "init"], $this);
        }

        // 资源注册触发
        foreach ($this->resource?->app as $resource) {
            $resource->run($this);
        }

        // 应用注册触发
        foreach ($appList as $vo) {
            call_user_func([new $vo, "register"], $this);
        }

        // 注解资源注册
        $this->resource->registerAttribute($this);

        // 注解路由注册
        $this->route->registerAttribute($this);

        // 普通路由注册
        foreach ($this->route->app as $route) {
            $route->run($this->web);
        }

        // 公共路由
        $this->web->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
            throw new HttpNotFoundException($request);
        });

        // 应用启动
        foreach ($appList as $vo) {
            call_user_func([new $vo, "boot"], $this);
        }
    }


    /**
     * 加载命令行
     * @return void
     */
    public function loadCommand(): void
    {
        $commands = App::config("command")->get("registers", []);

        $commands[] = BackupCommand::class;
        $commands[] = RestoreCommand::class;
        $commands[] = WebCommand::class;
        $this->command = Command::init($commands);

        // 注册模型迁移
        App::dbMigrate()->registerAttribute();
    }


    /**
     * 启动命令
     * @return void
     * @throws \Exception
     */
    public function run(): void
    {
        $this->command->run();
    }
}