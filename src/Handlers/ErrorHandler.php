<?php

declare(strict_types=1);

namespace Core\Handlers;

use Core\App;
use LogicException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpSpecializedException;
use Slim\Handlers\ErrorHandler as slimErrorHandler;
use Throwable;

class ErrorHandler extends slimErrorHandler
{
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
            'uri' => $this->request->getUri(),
            'query' => $this->request->getQueryParams(),
            'file' => [
                'file' => $this->exception->getFile(),
                'line' => $this->exception->getLine()
            ],
            'trace' => $this->exception->getTrace()
        ]);
    }
}
