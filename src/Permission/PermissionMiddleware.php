<?php
declare(strict_types=1);

namespace Core\Permission;

use Can\Permission\Can;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;

class PermissionMiddleware {

    public function __construct(
        public string $name,
        public string $model
    ) {
    }

    public function __invoke(Request $request, RequestHandler $handler): Response {
        $route = RouteContext::fromRequest($request)->getRoute();
        $routeName = $route->getName();
        Can::check($request, $this->model, $routeName);
        return $handler->handle($request);
    }
}