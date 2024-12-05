<?php

declare(strict_types=1);

namespace Core\Redis;

use Core\App;
use Core\Coroutine\ContextManage;
use Core\Coroutine\Pool;
use Core\Redis\Adapter\PhpRedisAdapter;
use Core\Redis\Adapter\PredisAdapter;
use Monolog\Level;
use Swoole\Coroutine;

class Redis
{

    private Pool $pool;

    public function __construct(public array $config)
    {
        $this->pool = new Pool(
            callback: function () use ($config) {
                return $this->connector($config);
            },
            maxIdle: $config['pool']['max_idle'] ?? 10,
            maxOpen: $config['pool']['max_open'] ?? 100,
            timeOut: $config['pool']['timeout'] ?? 10,
            idleTime: $config['pool']['idle_time'] ?? 60,
            logger: App::log('redis_pool', Level::Info),
        );
    }

    public function factory(): \Predis\ClientInterface|\Redis
    {
        return $this->getPool();
    }

    private function getPool(): \Predis\ClientInterface|\Redis
    {
        if (ContextManage::context() === null) {
            throw new \Exception('Context not initialized');
        }

        $redis = ContextManage::context()->getValue('redis');
        if ($redis !== null) {
            return $redis;
        }

        $redis = $this->pool->get();
        if (!$redis) {
            throw new \Exception('Connection pool exhausted');
        }

        ContextManage::context()->setValue('redis', $redis);

        Coroutine::defer(function () use ($redis) {
            $this->releasePool($redis);
            ContextManage::context()->setValue('redis', null);
        });

        return $redis;
    }

    /**
     * 释放连接
     * @param \Predis\ClientInterface|\Redis|null $redis
     * @return void
     */
    private function releasePool(\Predis\ClientInterface|\Redis $redis = null): void
    {
        if (!$redis) {
            $this->pool->put(null);
            return;
        }
        try {
            if ($redis->ping()) {
                $this->pool->put($redis);
            } else {
                $this->pool->put(null);
            }
        } catch (\Exception $e) {
            $this->pool->put(null);
        }
    }

    private function connector($config): \Predis\ClientInterface|\Redis
    {
        if (extension_loaded('redis')) {
            $client = new PhpRedisAdapter($config);
        } else {
            $client = new PredisAdapter($config);
        }
        return $client->getClient();
    }
}
