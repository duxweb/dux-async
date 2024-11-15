<?php

declare(strict_types=1);

use Core\Watcher\File;
use Psr\Log\NullLogger;
use Swoole\Coroutine;

// 辅助函数：递归删除目录
function removeDirectory(string $dir): void
{
  if (!is_dir($dir)) {
    return;
  }

  $files = array_diff(scandir($dir), ['.', '..']);
  foreach ($files as $file) {
    $path = $dir . '/' . $file;
    is_dir($path) ? removeDirectory($path) : unlink($path);
  }
  rmdir($dir);
}

beforeEach(function () use (&$tempDir) {
  // 设置临时测试目录
  $tempDir = sys_get_temp_dir() . '/watcher_test_' . uniqid();
  mkdir($tempDir);
});

afterEach(function () use (&$tempDir) {
  // 清理测试目录
  if (is_dir($tempDir)) {
    removeDirectory($tempDir);
  }
});

coroutineTest('create', function () use (&$tempDir) {
  $filePath = $tempDir . '/test.php';
  $watcher = new File([$tempDir], [], new NullLogger());

  // 创建文件并检测变化
  file_put_contents($filePath, '<?php echo "test";');
  $changes = $watcher->scanChanges();

  expect($changes)->toHaveCount(1);
  expect($changes[0])->toMatchArray([
    'type' => 'created',
    'path' => $filePath,
  ]);
});

coroutineTest('modify', function () use (&$tempDir) {
  $filePath = $tempDir . '/test.php';
  $watcher = new File([$tempDir], [], new NullLogger());

  // 创建文件
  file_put_contents($filePath, '<?php echo "original";');
  $watcher->scanChanges(); // 初始扫描

  // 修改文件
  file_put_contents($filePath, '<?php echo "modified";');
  $changes = $watcher->scanChanges();

  expect($changes)->toHaveCount(1);
  expect($changes[0])->toMatchArray([
    'type' => 'modified',
    'path' => $filePath,
  ]);
});

coroutineTest('delete', function () use (&$tempDir) {
  $filePath = $tempDir . '/test.php';
  $watcher = new File([$tempDir], [], new NullLogger());

  // 创建并注册文件
  file_put_contents($filePath, '<?php echo "test";');
  $watcher->scanChanges();

  // 删除文件
  unlink($filePath);
  $changes = $watcher->scanChanges();

  expect($changes)->toHaveCount(1);
  expect($changes[0])->toMatchArray([
    'type' => 'deleted',
    'path' => $filePath,
  ]);
});

coroutineTest('extensions', function () use (&$tempDir) {
  $phpFile = $tempDir . '/test.php';
  $jsFile = $tempDir . '/test.js';
  $txtFile = $tempDir . '/test.txt';

  $watcher = new File(
    [$tempDir],
    ['extensions' => ['php', 'js']],
    new NullLogger()
  );

  // 创建不同类型的文件
  file_put_contents($phpFile, '<?php echo "test";');
  file_put_contents($jsFile, 'console.log("test");');
  file_put_contents($txtFile, 'test content');

  $changes = $watcher->scanChanges();

  expect($changes)->toHaveCount(2);

  expect($changes)->toContain([
    'type' => 'created',
    'path' => $phpFile,
    'time' => $changes[0]['time']  // 由于时间戳是动态的，我们使用实际的值
  ]);

  expect($changes)->toContain([
    'type' => 'created',
    'path' => $jsFile,
    'time' => $changes[1]['time']  // 由于时间戳是动态的，我们使用实际的值
  ]);
});

coroutineTest('ignore', function () use (&$tempDir) {
  // 创建测试目录结构
  mkdir($tempDir . '/vendor');
  mkdir($tempDir . '/src');

  $srcFile = $tempDir . '/src/test.php';
  $vendorFile = $tempDir . '/vendor/test.php';

  $watcher = new File(
    [$tempDir],
    ['ignore_dirs' => ['vendor']],
    new NullLogger()
  );

  // 创建文件
  file_put_contents($srcFile, '<?php echo "src";');
  file_put_contents($vendorFile, '<?php echo "vendor";');

  $changes = $watcher->scanChanges();

  expect($changes)->toHaveCount(1);
  expect($changes[0]['path'])->toBe($srcFile);
});

coroutineTest('events', function () use (&$tempDir) {
  $filePath = $tempDir . '/test.php';
  $watcher = new File([$tempDir], [], new NullLogger());

  $createdCalled = false;
  $modifiedCalled = false;
  $deletedCalled = false;

  // 注册事件处理器
  $watcher->on('created', function ($data) use (&$createdCalled) {
    $createdCalled = true;
  });

  $watcher->on('modified', function ($data) use (&$modifiedCalled) {
    $modifiedCalled = true;
  });

  $watcher->on('deleted', function ($data) use (&$deletedCalled) {
    $deletedCalled = true;
  });

  // 启动监控
  $watcher->watch();

  // 测试文件操作
  file_put_contents($filePath, '<?php echo "test";');
  Coroutine::sleep(2);

  file_put_contents($filePath, '<?php echo "modified";');
  Coroutine::sleep(2);

  unlink($filePath);
  Coroutine::sleep(2);

  $watcher->stop();

  expect($createdCalled)->toBeTrue();
  expect($modifiedCalled)->toBeTrue();
  expect($deletedCalled)->toBeTrue();
});
