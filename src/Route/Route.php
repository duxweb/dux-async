<?php

declare(strict_types=1);

namespace Core\Route;

use Slim\Routing\RouteCollectorProxy;

class Route
{

    private array $middleware = [];
    private array $group = [];
    private array $data = [];
    private string $app = "";

    /**
     * @param string $pattern
     * @param object ...$middleware
     */
    public function __construct(public string $pattern = "", public ?string $name = "", object ...$middleware)
    {
        $this->middleware = $middleware;
    }

    public function setApp(string $app): void
    {
        $this->app = $app;
    }

    /**
     * 分组
     * @param string $pattern
     * @param object ...$middleware
     * @return Route
     */
    public function group(string $pattern, ?string $name = "", object ...$middleware): Route
    {
        $group = new Route($pattern, $name ? ($this->name ? $this->name . "." . $name : $name) : '', ...$middleware);
        $group->setApp($this->app);
        $this->group[] = $group;
        return $group;
    }

    /**
     * get
     * @param string $pattern
     * @param callable|object|string $callable
     * @param string $name
     * @param int $priority
     * @return void
     */
    public function get(string $pattern, callable|object|string $callable, string $name, int $priority = 0): void
    {
        $this->map(["GET"], $pattern, $callable, $name, [], $priority);
    }

    /**
     * post
     * @param string $pattern
     * @param callable|object|string $callable
     * @param string $name
     * @param int $priority
     * @return void
     */
    public function post(string $pattern, callable|object|string $callable, string $name, int $priority = 0): void
    {
        $this->map(["POST"], $pattern, $callable, $name, [], $priority);
    }

    /**
     * put
     * @param string $pattern
     * @param callable|object|string $callable
     * @param string $name
     * @param int $priority
     * @return void
     */
    public function put(string $pattern, callable|object|string $callable, string $name, int $priority = 0): void
    {
        $this->map(["PUT"], $pattern, $callable, $name, [], $priority);
    }

    /**
     * delete
     * @param string $pattern
     * @param callable|object|string $callable
     * @param string $name
     * @param int $priority
     * @return void
     */
    public function delete(string $pattern, callable|object|string $callable, string $name, int $priority = 0): void
    {
        $this->map(["DELETE"], $pattern, $callable, $name, [], $priority);
    }

    /**
     * options
     * @param string $pattern
     * @param callable|object|string $callable
     * @param string $name
     * @param int $priority
     * @return void
     */
    public function options(string $pattern, callable|object|string $callable, string $name, int $priority = 0): void
    {
        $this->map(["OPTIONS"], $pattern, $callable, $name, [], $priority);
    }

    /**
     * patch
     * @param string $pattern
     * @param callable|object|string $callable
     * @param string $name
     * @param int $priority
     * @return void
     */
    public function patch(string $pattern, callable|object|string $callable, string $name, int $priority = 0): void
    {
        $this->map(["PATCH"], $pattern, $callable, $name, [], $priority);
    }

    /**
     * any
     * @param string $pattern
     * @param callable|object|string $callable
     * @param string $name
     * @param int $priority
     * @return void
     */
    public function any(string $pattern, callable|object|string $callable, string $name, int $priority = 0): void
    {
        $this->map(["ANY"], $pattern, $callable, $name, [], $priority);
    }


    public static array $actions = ['list', 'show', 'create', 'edit', 'store', 'delete'];

    /**
     * resources
     * @param string $class
     * @param array|false $actions
     * @param bool $softDelete
     * @return Route
     */
    public function resources(string $class, array|false $actions = [], bool $softDelete = false): self
    {
        if ($actions === false) {
            return $this;
        }

        if (!$actions) {
            $actions = self::$actions;
        }

        $actions = array_intersect(self::$actions, $actions);

        if ($softDelete) {
            $actions = [...$actions, 'trash', 'restore'];
        }

        if (in_array("list", $actions)) {
            $this->get('', "$class:list", "list", 100);
        }
        if (in_array("show", $actions)) {
            $this->get("/{id:[0-9]+}", "$class:show", "show", 100);
        }
        if (in_array("create", $actions)) {
            $this->post("", "$class:create", "create", 100);
        }
        if (in_array("edit", $actions)) {
            $this->put("/{id:[0-9]+}", "$class:edit", "edit", 100);
        }
        if (in_array("store", $actions)) {
            $this->patch("/{id:[0-9]+}", "$class:store", "store", 100);
        }
        if (in_array("delete", $actions)) {
            $this->delete("/{id:[0-9]+}", "$class:delete", "delete", 100);
            $this->delete("", "$class:deleteMany", "deleteMany", 100);
        }
        if (in_array("trash", $actions)) {
            $this->delete("/{id:[0-9]+}/trash", "$class:trash", "trash", 100);
        }
        if (in_array("restore", $actions)) {
            $this->put("/{id:[0-9]+}/restore", "$class:restore", "restore", 100);
        }
        return $this;
    }

    /**
     * map
     * @param array $methods [GET, POST, PUT, DELETE, OPTIONS, PATCH]
     * @param string $pattern
     * @param string|callable $callable function(Request $request, Response $response)
     * @param string $name
     * @param array $middleware
     * @param int $priority
     * @return void
     */
    public function map(string|array $methods, string $pattern, string|callable $callable, ?string $name, array $middleware = [], int $priority = 0): void
    {
        $this->data[] = [
            "methods" => is_array($methods) ? $methods : [$methods],
            "pattern" => $pattern,
            "callable" => $callable,
            "name" => $name ? ($this->name ? $this->name . "." . $name : $name) : '',
            "middleware" => $middleware ?: [],
            "priority" => $priority
        ];
    }

    /**
     * 解析树形路由
     * @param string $pattern
     * @param array $middleware
     * @return array
     */
    public function parseTree(string $pattern = "", array $middleware = []): array
    {
        $pattern = $pattern ?: $this->pattern;
        foreach ($this->middleware as $vo) {
            $middleware[] = get_class($vo);
        }
        $data = [];
        foreach ($this->data as $route) {
            $route["pattern"] = $pattern . $route["pattern"];
            $routeMiddleware = array_map(function ($item) {
                return get_class($item);
            }, $route['middleware']);

            $data[] = [
                "name" => $route["name"],
                "pattern" => $route["pattern"],
                "methods" => $route["methods"],
                "middleware" => array_filter([...$middleware, ...$routeMiddleware])
            ];
        }
        foreach ($this->group as $group) {
            $data[] = $group->parseTree($pattern . $group->pattern, $middleware);
        }

        return [
            "pattern" => $pattern,
            "data" => $data
        ];
    }


    /**
     * 解析路由列表
     * @param string $pattern
     * @param array $middleware
     * @return array
     */
    public function parseData(string $pattern = "", array $middleware = []): array
    {
        $pattern = $pattern ?: $this->pattern;
        foreach ($this->middleware as $vo) {
            $middleware[] = get_class($vo);
        }
        $data = [];
        foreach ($this->data as $route) {
            $route["pattern"] = $pattern . $route["pattern"];
            $routeMiddleware = array_map(function ($item) {
                return get_class($item);
            }, $route['middleware']);

            $data[] = [
                "name" => $route["name"],
                "pattern" => $route["pattern"],
                "methods" => $route["methods"],
                "middleware" => array_filter([...$middleware, ...$routeMiddleware])
            ];
        }
        foreach ($this->group as $group) {
            $data = [...$data, ...$group->parseData($pattern . $group->pattern, $middleware)];
        }
        return $data;
    }


    /**
     * 运行路由注册
     * @param RouteCollectorProxy $route
     * @return void
     */
    public function run(RouteCollectorProxy $route): void
    {
        $dataList = $this->data;
        $groupList = $this->group;
        $app = $this->app;
        $route = $route->group($this->pattern, function (RouteCollectorProxy $group) use ($dataList, $groupList, $app) {
            $priority = array_column($dataList, 'priority');
            array_multisort($priority, SORT_ASC, $dataList);
            foreach ($dataList as $item) {
                $groupRoute = $group->map($item["methods"], $item["pattern"], $item["callable"])->setName($item["name"])->setArgument("app", $app);
                if ($item['middleware']) {
                    foreach ($item['middleware'] as $vo) {
                        $groupRoute->add($vo);
                    }
                }
            }
            foreach ($groupList as $item) {
                $item->run($group);
            }
        });
        foreach ($this->middleware as $middle) {
            $route->add($middle);
        }
    }
}
