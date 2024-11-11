<?php
declare(strict_types=1);
namespace Core\Handlers;

use Core\App;
use Slim\Error\Renderers\PlainTextErrorRenderer;

class ErrorPlainRenderer extends PlainTextErrorRenderer
{
    use ErrorRendererTrait;
    public function __construct() {
        $this->defaultErrorTitle = App::$bootstrap->exceptionTitle;
        $this->defaultErrorDescription = App::$bootstrap->exceptionDesc;
    }
}