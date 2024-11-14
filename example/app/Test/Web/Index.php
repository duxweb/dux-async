<?php

namespace App\Test\Web;

use Core\Route\Attribute\Route;
use Core\Route\Attribute\RouteGroup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[RouteGroup(app: 'web', route: '/')]
class Index
{

    #[Route(methods: 'GET', route: '')]
    public function location(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $view = \Core\App::view('web');
        $view->render('index.latte');
        return sendText($response, "ok");
    }

}