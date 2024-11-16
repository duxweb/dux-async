<?php

declare(strict_types=1);

namespace Core\Queue\Contracts;

interface QueueDriverInterface
{
  /**
   * 推送消息到队列
   * @param string $queue 队列名称
   * @param array $params 消息数据
   */
  public function push(string $queue, array $params): bool;

  /**
   * 获取一个消息
   * @param string $queue 队列名称
   */
  public function pop(string $queue): array|null;

  /**
   * 获取队列大小
   * @param string $queue 队列名称
   */
  public function size(string $queue): int;
}
