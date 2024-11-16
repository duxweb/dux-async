<?php

declare(strict_types=1);

namespace Core\Queue\Drivers;

use Core\App;
use Core\Queue\Contracts\QueueDriverInterface;

class RedisDriver implements QueueDriverInterface
{
  private \Predis\ClientInterface|\Redis $redis;
  private string $prefix = 'queue:';

  public function __construct(array $config = [])
  {
    $this->redis = App::redis($config['name'] ?? 'default');
  }

  public function push(string $queue, array $data): bool
  {
    $now = time();

    // 如果是延时消息
    if (isset($data['available_at']) && $data['available_at'] > $now) {
      return (bool)$this->redis->zAdd(
        $this->getDelayedKey($queue),
        $data['available_at'],
        serialize($data)
      );
    }

    // 普通消息直接入队
    return (bool)$this->redis->lPush(
      $this->prefix . $queue,
      serialize($data)
    );
  }

  public function pop(string $queue): array|null
  {
    $now = time();

    // 使用 ZRANGEBYSCORE 命令原子性地获取并移除到期的消息
    $script = <<<'LUA'
        local key = KEYS[1]
        local now = ARGV[1]

        -- 获取第一个到期的消息
        local messages = redis.call('ZRANGEBYSCORE', key, '-inf', now, 'LIMIT', 0, 1)
        if #messages > 0 then
            -- 如果找到消息，立即删除并返回
            redis.call('ZREM', key, messages[1])
            return messages[1]
        end
        return false
    LUA;

    // Redis 扩展的 eval 方法参数顺序：script, keys array, args array
    $result = $this->redis->eval(
      $script,
      [$this->getDelayedKey($queue), $now],
      1
    );

    // 如果在延迟队列中找到消息，则返回
    if ($result !== false) {
      return unserialize($result);
    }

    // 获取普通队列消息
    $data = $this->redis->rPop($this->prefix . $queue);
    return $data ? unserialize($data) : null;
  }

  public function size(string $queue): int
  {
    // 获取普通队列长度
    $normalSize = $this->redis->lLen($this->prefix . $queue);

    // 获取延迟队列长度
    $delayedSize = $this->redis->zCard($this->getDelayedKey($queue));

    return $normalSize + $delayedSize;
  }

  private function getDelayedKey(string $queue): string
  {
    return $this->prefix . $queue . ':delayed';
  }
}
