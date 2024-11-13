<?php
declare(strict_types=1);

namespace Core\Lock;

use Core\App;
use Core\Handlers\Exception;
use Core\Lock\Store\MemoryStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;

class Lock {

    public static function init(string $type = 'memory'): LockFactory
    {
        $store = match ($type) {
            'redis' => new RedisStore(App::redis()),
            'memory' => new MemoryStore(),
            default => throw new Exception('Lock type does not exist')
        };
        return new LockFactory($store);
    }
}