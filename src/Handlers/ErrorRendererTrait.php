<?php
declare(strict_types=1);
namespace Core\Handlers;

use Throwable;

trait ErrorRendererTrait
{

    protected function getErrorTitle(Throwable $exception): string {
        return $exception->getMessage() ?: parent::getErrorTitle($exception);
    }
}