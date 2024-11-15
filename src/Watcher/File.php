<?php

declare(strict_types=1);

namespace Core\Watcher;

use Nette\Utils\Finder;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * 文件监控类
 * 用于监控指定目录下的文件变化，支持创建、修改、删除事件
 */
class File
{
  private Channel $channel;
  private array $watchPaths;
  private array $fileHashes = [];
  private array $handlers = [];
  private bool $running = false;
  private array $options;
  private ?LoggerInterface $logger;

  /**
   * @param array $watchPaths 要监控的路径数组
   * @param array $options 配置选项
   * @param LoggerInterface|null $logger 日志记录器
   */
  public function __construct(
    array $watchPaths = [],
    array $options = [],
    ?LoggerInterface $logger = null
  ) {
    $this->watchPaths = $watchPaths;
    $this->channel = new Channel(100);
    $this->logger = $logger;
    $this->options = array_merge([
      'extensions' => ['php'],
      'ignore_dirs' => ['vendor'],
      'check_interval' => 1,
      'include_hidden' => false,
    ], $options);
  }

  /**
   * 注册事件处理器
   * @param string $event 事件类型 (created|modified|deleted)
   * @param callable $handler 处理器函数
   */
  public function on(string $event, callable $handler): void
  {
    $this->handlers[$event][] = $handler;
    $this->logger?->debug('Event handler registered', ['event' => $event]);
  }

  /**
   * 启动文件监控
   */
  public function watch(): void
  {
    $this->running = true;
    $this->logger?->debug('File watcher started', [
      'paths' => $this->watchPaths,
      'options' => $this->options
    ]);

    // 启动扫描协程
    Coroutine::create(function () {
      $this->scanLoop();
    });

    // 启动事件处理协程
    Coroutine::create(function () {
      $this->eventLoop();
    });
  }

  /**
   * 扫描文件变化
   * @return array 变化列表
   */
  public function scanChanges(): array
  {
    $changes = [];
    $currentFiles = [];

    foreach ($this->watchPaths as $path) {
      if (!is_dir($path)) {
        continue;
      }

      try {
        // 使用 glob 递归搜索文件
        $files = $this->globRecursive($path . '/*');

        foreach ($files as $filePath) {
          // 检查是否需要排除该文件
          if ($this->shouldExcludeFile($filePath)) {
            continue;
          }

          $currentFiles[$filePath] = true;

          // 检查文件是否为新建
          if (!isset($this->fileHashes[$filePath])) {
            $hash = $this->getFileHash($filePath);
            $this->fileHashes[$filePath] = $hash;
            $changes[] = [
              'type' => 'created',
              'path' => $filePath,
              'time' => time(),
            ];
          } else {
            // 检查文件是否被修改
            $currentHash = $this->getFileHash($filePath);
            if ($this->fileHashes[$filePath] !== $currentHash) {
              $this->fileHashes[$filePath] = $currentHash;
              $changes[] = [
                'type' => 'modified',
                'path' => $filePath,
                'time' => time(),
              ];
            }
          }
        }
      } catch (\Throwable $e) {
        $this->logger?->error('Error scanning directory', [
          'path' => $path,
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);
        continue;
      }
    }

    // 检查删除的文件
    foreach ($this->fileHashes as $filePath => $hash) {
      if (!isset($currentFiles[$filePath])) {
        unset($this->fileHashes[$filePath]);
        $changes[] = [
          'type' => 'deleted',
          'path' => $filePath,
          'time' => time(),
        ];
      }
    }

    return $changes;
  }

  /**
   * 停止文件监控
   */
  public function stop(): void
  {
    $this->logger?->debug('Stopping file watcher');
    $this->running = false;
    Coroutine::sleep(0.1);
    $this->channel->close();
    $this->logger?->debug('File watcher stopped');
  }

  /**
   * 获取监控的路径列表
   */
  public function getWatchPaths(): array
  {
    return $this->watchPaths;
  }

  /**
   * 扫描循环
   */
  private function scanLoop(): void
  {
    $this->logger?->debug('Scanner coroutine started');
    while ($this->running) {
      try {
        $changes = $this->scanChanges();
        if (!empty($changes)) {
          $this->logger?->debug('Changes detected', ['changes' => $changes]);
          $this->channel->push($changes, 1.0);
        }
      } catch (\Throwable $e) {
        $this->logger?->error('Scanner error', [
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);
      }
      Coroutine::sleep($this->options['check_interval']);
    }
    $this->logger?->debug('Scanner coroutine stopped');
  }

  /**
   * 事件处理循环
   */
  private function eventLoop(): void
  {
    $this->logger?->debug('Event handler coroutine started');
    while ($this->running) {
      try {
        $changes = $this->channel->pop(1.0);
        if ($changes === false) {
          continue;
        }
        foreach ($changes as $change) {
          $this->logger?->debug('Processing change', ['change' => $change]);
          $this->triggerEvent($change['type'], $change);
        }
      } catch (\Throwable $e) {
        $this->logger?->error('Event handler error', [
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);
      }
    }
    $this->logger?->debug('Event handler coroutine stopped');
  }

  /**
   * 触发事件
   */
  private function triggerEvent(string $event, array $data): void
  {
    if (!isset($this->handlers[$event])) {
      return;
    }

    foreach ($this->handlers[$event] as $handler) {
      try {
        $handler($data);
        $this->logger?->info('Event triggered', [
          'event' => $event,
          'data' => $data
        ]);
      } catch (\Throwable $e) {
        $this->logger?->error('Event handler error', [
          'event' => $event,
          'error' => $e->getMessage()
        ]);
      }
    }
  }

  /**
   * 获取文件哈希值
   */
  private function getFileHash(string $filePath): string
  {
    return (string) md5_file($filePath);
  }

  /**
   * 递归获取目录下的所有文件
   */
  private function globRecursive(string $pattern, int $flags = 0): array
  {
    $files = glob($pattern, $flags);
    $files = $files === false ? [] : $files;

    foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
      $files = array_merge($files, $this->globRecursive($dir . '/' . basename($pattern), $flags));
    }

    return $files;
  }

  /**
   * 检查是否应该排除该文件
   */
  private function shouldExcludeFile(string $filePath): bool
  {
    // 检查文件扩展名
    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    if (!in_array($extension, $this->options['extensions'], true)) {
      return true;
    }

    // 检查是否在忽略目录中
    foreach ($this->options['ignore_dirs'] as $ignoreDir) {
      if (str_contains($filePath, '/' . $ignoreDir . '/')) {
        return true;
      }
    }

    // 检查是否为隐藏文件
    if (!$this->options['include_hidden'] && str_starts_with(basename($filePath), '.')) {
      return true;
    }

    return false;
  }
}
