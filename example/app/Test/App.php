<?php
declare(strict_types=1);

namespace App\Test;

use Core\App as CoreApp;
use Core\App\AppExtend;
use Core\Bootstrap;
use Core\Route\Route;

class App extends AppExtend
{

    public function init(Bootstrap $app): void
    {

        CoreApp::route()->set("web", new Route(""));
    }
    
}
