<?php
declare(strict_types=1);

namespace Core\Database;

use Core\Database\Redis\PhpRedisAdapter;
use Core\Database\Redis\PredisAdapter;

class Redis
{
    private PhpRedisAdapter|PredisAdapter $client;

    public function __construct(public array $config)
    {
        if (extension_loaded('redis')) {
            $this->client = new PhpRedisAdapter($config);
        } else {
            $this->client = new PredisAdapter($config);
        }
    }

    public function connect(): \Predis\ClientInterface|\Redis
    {
        return $this->client->getClient();
    }

}