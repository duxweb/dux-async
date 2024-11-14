<?php
declare(strict_types=1);
namespace Core\Handlers;

use Slim\Exception\HttpException;
use Throwable;

trait ErrorRendererTrait
{
    protected function getErrorTitle(Throwable $exception): string
    {
        if ($exception instanceof HttpException) {
            return $exception->getTitle();
        }

        return __('error.errorTitle', 'common');
    }

    protected function getErrorDescription(Throwable $exception): string
    {
        if ($exception instanceof HttpException) {
            return $exception->getDescription();
        }

        return __('error.errorMessage', 'common');
    }
}