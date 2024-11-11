<?php
declare(strict_types=1);

namespace Core\Redis;


use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use function Amp\Redis\createRedisClient;

class Redis
{
    public RedisClient $client;
    public function __construct(public array $config)
    {
        $redisConfig = RedisConfig::fromUri(($config["host"] ?? '127.0.0.1') . ((int)$options["port"] ?? 6379),  (float)$config["timeout"]);
        if ($config["password"]) {
            $redisConfig = $redisConfig->withPassword($config["password"]);
        }
        $redisConfig = $redisConfig->withDatabase($this->config['database'] ?: 0);
        $this->client = createRedisClient($redisConfig);
    }

    public function getClient(): RedisClient
    {
        return $this->client;
    }
}