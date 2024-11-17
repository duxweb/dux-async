<?php

declare(strict_types=1);

namespace Core\Views;

use Core\App;

class Render
{

    public static function init(): RenderCache
    {
        $latte = new RenderCache;
        return $latte;
    }
}
