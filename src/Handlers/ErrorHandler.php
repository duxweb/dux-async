<?php

declare(strict_types=1);

namespace Core\Handlers;

use Core\App;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpSpecializedException;
use Slim\Handlers\ErrorHandler as slimErrorHandler;
use Throwable;

class ErrorHandler extends slimErrorHandler
{

    protected function getRenderer(?string $contentType = null): callable
    {
        $renderer = $this->errorRenderers[$contentType];
        return $this->callableResolver->resolve($renderer);
    }

    protected function respond(): ResponseInterface
    {
        $contentType = $this->determineContentType($this->request);
        $response = $this->responseFactory->createResponse($this->statusCode);
        if ($contentType !== null && array_key_exists($contentType, $this->errorRenderers)) {
            $response = $response->withHeader('Content-type', $contentType);
        } else {
            $response = $response->withHeader('Content-type', $this->defaultErrorRendererContentType);
        }

        if ($this->exception instanceof HttpMethodNotAllowedException) {
            $allowedMethods = implode(', ', $this->exception->getAllowedMethods());
            $response = $response->withHeader('Allow', $allowedMethods);
        }

        $renderer = $this->getRenderer($response->getHeaderLine('Content-type'));
        $body = call_user_func($renderer, $this->exception, $this->displayErrorDetails);
        if ($body !== false) {
            $response->getBody()->write($body);
        }
        return $response;
    }


    protected function determineStatusCode(): int
    {
        if ($this->method === 'OPTIONS') {
            return 200;
        }
        if ($this->exception instanceof HttpException || $this->exception instanceof Exception) {
            return $this->exception->getCode() ?: 500;
        }
        return 500;
    }

    protected function logError(string $error): void
    {
        if (
            $this->statusCode == 404 ||
            $this->exception instanceof HttpSpecializedException ||
            $this->exception instanceof LogicException ||
            $this->exception instanceof Exception
        ) {
            return;
        }

        App::log()->error($this->exception->getMessage(), [
            'file' => [
                'file' => $this->exception->getFile(),
                'line' => $this->exception->getLine()
            ],
            'trace' => $this->exception->getTrace()
        ]);
    }
}
