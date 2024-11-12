<?php
declare(strict_types=1);

namespace Core\Database;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;

class Db
{

    public static function init(array $configs): Manager
    {
        $capsule = new Manager;

        $capsule->getDatabaseManager()->extend('mysql', function($config, $name) {
            $config['name'] = $name;
            return new \Core\Database\Connection\MySqlConnection($config);
        });


        foreach ($configs as $key => $config) {
            $capsule->addConnection($config, $key);
        }
        $event = new Dispatcher(new Container);
        $capsule->setEventDispatcher($event);
        $capsule->bootEloquent();
        return $capsule;
    }

}
