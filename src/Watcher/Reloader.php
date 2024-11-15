<?php

declare(strict_types=1);

namespace Core\Watcher;

use Core\App;
use Core\Coroutine\Timer;
use Psr\Log\LoggerInterface;

class Reloader
{
  private array $options;
  private array $reloadQueue = [];
  private ?Timer $timer = null;
  private LoggerInterface $logger;

  public function __construct(array $options = [], LoggerInterface $logger)
  {
    $this->options = array_merge([
      'debounce_time' => 300,
    ], $options);

    $this->logger = $logger;
  }

  /**
   * 处理文件变更
   */
  public function handleFileChange(array $event): void
  {
    $path = $event['path'];
    $type = $event['type'];

    $this->reloadQueue[$path] = [
      'type' => $type,
      'time' => time(),
    ];

    // 防抖处理
    if ($this->timer) {
      $this->timer->stop();
    }

    $this->timer = new Timer();
    $this->timer->after($this->options['debounce_time'], function () {
      $this->performReload();
    });
  }

  /**
   * 执行重载
   */
  private function performReload(): void
  {
    if (empty($this->reloadQueue)) {
      return;
    }

    $changedFiles = [];

    foreach ($this->reloadQueue as $path => $info) {
      if ($info['type'] !== 'deleted') {
        $changedFiles[] = $path;
      }
    }

    // 添加变更文件日志
    if (!empty($changedFiles)) {
      $this->logger->info('Changed files: ' . implode(', ', $changedFiles));
    }

    try {
      $this->reInit();

      $this->logger->info('Hot reload completed.');
    } catch (\Throwable $e) {
      $this->logger->error('Hot reload failed: ' . $e->getMessage());
    }

    $this->reloadQueue = [];
  }

  /**
   * 重新初始化应用
   */
  private function reInit(): void
  {
    try {
      // 重新初始化应用
      App::init();

      $this->logger->info('Application rebooted.');
    } catch (\Throwable $e) {
      $this->logger->error('Reboot failed: ' . $e->getMessage());
      throw $e;
    }
  }
}
