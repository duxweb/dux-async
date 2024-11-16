<?php

declare(strict_types=1);

namespace Core\Queue\Drivers;

use Core\Queue\Contracts\QueueDriverInterface;
use PDO;

class SqliteDriver implements QueueDriverInterface
{
  private PDO $db;

  public function __construct(array $config = [])
  {
    // 默认使用内存模式
    $mode = $config['mode'] ?? 'memory';

    $dsn = match ($mode) {
      'memory' => 'sqlite::memory:',
      'file' => 'sqlite:' . data_path('queue.db'),
      default => throw new \InvalidArgumentException("Invalid SQLite mode: {$mode}")
    };

    $this->db = new PDO($dsn);

    // 设置PDO属性
    $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 对于内存模式，开启WAL模式提升性能
    if ($mode === 'memory') {
      $this->db->exec('PRAGMA journal_mode=WAL');
      $this->db->exec('PRAGMA synchronous=NORMAL');
    }

    // 创建队列表
    $this->db->exec('
      CREATE TABLE IF NOT EXISTS queue_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        queue VARCHAR(64) NOT NULL,
        data TEXT NOT NULL,
        available_at INTEGER NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      )
    ');

    // 创建索引
    $this->db->exec('
      CREATE INDEX IF NOT EXISTS idx_queue_available 
      ON queue_messages(queue, available_at)
    ');
  }

  public function push(string $queue, array $data): bool
  {
    // 确保消息包含必需字段
    if (!isset($data['available_at'])) {
      $data['available_at'] = time();
    }

    $stmt = $this->db->prepare('
        INSERT INTO queue_messages (queue, data, available_at) 
        VALUES (:queue, :data, :available_at)
    ');

    return $stmt->execute([
      ':queue' => $queue,
      ':data' => serialize($data),
      ':available_at' => $data['available_at']  // 使用消息中的 available_at
    ]);
  }

  public function pop(string $queue): array|null
  {
    $this->db->beginTransaction();

    try {
      $now = time();

      // 只获取可执行的消息（available_at <= now）
      $stmt = $this->db->prepare('
            SELECT id, data, available_at 
            FROM queue_messages 
            WHERE queue = :queue 
            AND available_at <= :now
            ORDER BY available_at ASC, created_at ASC
            LIMIT 1
        ');

      $stmt->execute([
        ':queue' => $queue,
        ':now' => $now
      ]);

      $row = $stmt->fetch();

      if ($row) {
        $message = unserialize($row['data']);

        if (!is_array($message)) {
          $this->db->rollBack();
          return null;
        }

        // 删除已获取的消息
        $stmt = $this->db->prepare('
                DELETE FROM queue_messages 
                WHERE id = :id
            ');

        $stmt->execute([':id' => $row['id']]);
        $this->db->commit();

        return $message;
      }

      $this->db->commit();
      return null;
    } catch (\Exception $e) {
      $this->db->rollBack();
      throw $e;
    }
  }

  public function size(string $queue): int
  {
    $stmt = $this->db->prepare('
      SELECT COUNT(*) as count 
      FROM queue_messages 
      WHERE queue = :queue
    ');

    $stmt->execute([':queue' => $queue]);
    $row = $stmt->fetch();

    return (int)$row['count'];
  }
}
