<?php

namespace Core\Middleware;

use Core\App;
use Core\Coroutine\Context;
use Core\Coroutine\ContextManage;
use Core\Utils\Fmt;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Slim\Routing\RouteContext;
use Swoole\Coroutine;

final class LangMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $acceptLanguage = $request->getHeaderLine('Accept-Language');
        $lang = $this->parseAcceptLanguage($acceptLanguage);

        ContextManage::context()->setValue('lang', $lang);
        $request = $request->withAttribute('lang', $lang);
        return $handler->handle($request);
    }

    private function parseAcceptLanguage(string $acceptLanguage): string
    {
        if (empty($acceptLanguage)) {
            return 'en-US';
        }

        $languages = explode(',', $acceptLanguage);

        $primaryLang = trim($languages[0]);

        if (str_contains($primaryLang, ';')) {
            $primaryLang = explode(';', $primaryLang)[0];
        }

        return trim($primaryLang);
    }
}
