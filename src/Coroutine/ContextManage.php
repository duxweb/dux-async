<?php

namespace Core\Coroutine;

use Core\Coroutine\Context;
use Swoole\Coroutine;

class ContextManage
{
    public static array $map = [];

    public static function init(): void
    {
        self::$map ??= [];
    }

    public static function context(): ?Context
    {
        $cid = Coroutine::getCid();
        if ($cid < 0) {
            return null;
        }
        if (!isset(self::$map[$cid])) {
            self::$map[$cid] = new Context();
        }

        return self::$map[$cid];
    }

    public static function destroy(): void
    {
        $cid = Coroutine::getCid();
        if (!isset(self::$map[$cid])) {
            return;
        }
        self::$map[$cid]->destroy();
        unset(self::$map[$cid]);
    }
}