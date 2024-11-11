<?php

namespace Core\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Core\App;

final class LangMiddleware implements Middleware
{

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $lang = $request->getHeader('Accept-Language');
        $lang = explode(',', $lang)[0];
        App::di()->set('lang', $lang);

        $response = $requestHandler->handleRequest($request);
        return $response;
    }
}