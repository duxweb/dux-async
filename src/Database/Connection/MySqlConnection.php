<?php

namespace Core\Database\Connection;

use Core\App;
use Core\Coroutine\ContextManage;
use Core\Coroutine\Pool;
use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Database\MySqlConnection as MySqlConnectionBase;
use PDO;
use Swoole\Coroutine;

class MySqlConnection extends MySqlConnectionBase
{
    protected Pool $pool;

    public function __construct(array $config)
    {
        $this->pool = new Pool(
            callback: function () use ($config) {
                $con = new MySqlConnector();
                $config['options'] = [
                    PDO::ATTR_PERSISTENT => false,
                ];
                return $con->connect($config);
            },
            minSize: $config['pool']['min'] ?? 30,
            maxSize: $config['pool']['max'] ?? 300,
            timeOut: $config['pool']['timeout'] ?? 10,
            idleTime: $config['pool']['idle_time'] ?? 60,
            logger: App::log("database_pool"),
            debug: true
        );

        parent::__construct(function () {
            return $this->getPool();
        }, $config['database'], $config['prefix'], $config);
    }

    // 获取连接
    public function getPool(): PDO
    {
        // 获取上下文连接
        $pdo = ContextManage::context()->getValue('pdo');
        if ($pdo !== null) {
            return $pdo;
        }

        // 获取连接池连接
        $pdo = $this->pool->get();
        if (!$pdo) {
            throw new \PDOException('Connection pool exhausted');
        }

        // 设置上下文连接
        ContextManage::context()->setValue('pdo', $pdo);

        // 协程结束释放连接
        Coroutine::defer(function () use ($pdo) {
            $this->releasePool($pdo);
            ContextManage::context()->setValue('pdo', null);
        });

        return $pdo;
    }

    /**
     * 释放连接
     * @param PDO $pdo
     * @return void
     */
    protected function releasePool(PDO $pdo): void
    {
        try {
            // 检查连接状态
            if ($pdo->inTransaction() || $pdo->getAttribute(\PDO::ATTR_SERVER_INFO) !== false) {
                $this->pool->put($pdo);
            } else {
                $this->pool->put(null);
            }
        } catch (\PDOException $e) {
            // 异常丢弃链接
            $this->pool->put(null);
        }
    }

    public function disconnect()
    {
        $this->pool->close();
        parent::disconnect();
    }

}
