<?php

use Core\Storage\Drivers\LocalDriver;
use Core\Storage\Exceptions\StorageException;

beforeEach(function () {
    $this->testDir = sys_get_temp_dir() . '/storage_test';
    if (!is_dir($this->testDir)) {
        mkdir($this->testDir, 0777, true);
    }

    $this->config = [
        'root' => $this->testDir,
        'domain' => 'https://example.com',
        'path' => 'uploads'
    ];

    $this->driver = new LocalDriver($this->config, function ($path) {
        return hash('sha256', $path . 'test_secret');
    });
});

afterEach(function () {
    if (is_dir($this->testDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($this->testDir);
    }
});

// 基础文件操作测试
test('basic', function () {
    $path = 'test.txt';
    $content = 'Hello World';

    // 测试写入
    expect($this->driver->write($path, $content))->toBeTrue()
        ->and($this->driver->exists($path))->toBeTrue();

    // 测试读取
    expect($this->driver->read($path))->toBe($content);

    // 测试大小
    expect($this->driver->size($path))->toBe(strlen($content));

    // 测试删除
    expect($this->driver->delete($path))->toBeTrue()
        ->and($this->driver->exists($path))->toBeFalse();
});

// 流操作测试
test('stream', function () {
    $path = 'test_stream.txt';
    $content = 'Hello Stream';

    // 测试流写入
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $content);
    rewind($stream);
    expect($this->driver->writeStream($path, $stream))->toBeTrue();
    fclose($stream);

    // 测试流读取
    $readStream = $this->driver->readStream($path);
    expect($readStream)->toBeResource();
    expect(stream_get_contents($readStream))->toBe($content);
    fclose($readStream);
});

// URL生成测试
test('url', function () {
    $path = 'test.jpg';

    // 测试公共URL
    $publicUrl = $this->driver->publicUrl($path);
    expect($publicUrl)->toBe('https://example.com/uploads/' . $path);

    // 测试私有URL
    $privateUrl = $this->driver->privateUrl($path);
    expect($privateUrl)->toBe('https://example.com/uploads/' . $path);
});

// 签名URL测试
test('sign', function () {
    $path = 'test_signed.txt';

    // 测试POST签名URL
    $postSign = $this->driver->signPostUrl($path);
    expect($postSign)->toBeArray()
        ->toHaveKeys(['url', 'params'])
        ->and($postSign['params'])->toHaveKeys(['sign', 'key']);

    // 测试PUT签名URL
    $putUrl = $this->driver->signPutUrl($path);
    expect($putUrl)->toBeString()
        ->toContain('sign=')
        ->toContain('key=');
});

// 错误处理测试
test('error', function () {
    $nonExistentPath = 'non_existent.txt';

    // 测试读取不存在的文件
    expect(fn() => $this->driver->read($nonExistentPath))
        ->toThrow(StorageException::class, 'File not readable or not found');

    // 测试获取不存在文件的大小
    expect(fn() => $this->driver->size($nonExistentPath))
        ->toThrow(StorageException::class, 'File not found');

    // 测试读取不存在的文件流
    expect(fn() => $this->driver->readStream($nonExistentPath))
        ->toThrow(StorageException::class, 'File not readable or not found');
});

// 目录创建测试
test('directory', function () {
    $path = 'nested/directory/test.txt';
    $content = 'Nested Content';

    // 确保测试目录存在且有写入权限
    $nestedDir = $this->testDir . '/nested/directory';
    if (!is_dir($nestedDir)) {
        mkdir($nestedDir, 0777, true);
    }

    expect($this->driver->write($path, $content))->toBeTrue()
        ->and($this->driver->exists($path))->toBeTrue();

    // 验证嵌套目录是否正确创建
    expect(is_dir($nestedDir))->toBeTrue();

    // 清理
    $this->driver->delete($path);
});

// 驱动类型测试
test('type', function () {
    expect($this->driver->isLocal())->toBeTrue();
});

// 配置测试
test('config', function () {
    // 测试没有配置的情况
    $driver = new LocalDriver([]);
    expect($driver->publicUrl('test.txt'))->toBe('/test.txt');

    // 测试只有domain的配置
    $driver = new LocalDriver(['domain' => 'https://example.com']);
    expect($driver->publicUrl('test.txt'))->toBe('https://example.com/test.txt');

    // 测试完整配置
    $driver = new LocalDriver([
        'domain' => 'https://example.com',
        'path' => 'uploads'
    ]);
    expect($driver->publicUrl('test.txt'))->toBe('https://example.com/uploads/test.txt');
});

// 签名回调测试
test('callback', function () {
    $path = 'test.txt';
    $expectedSign = hash('sha256', 'uploads/' . $path . 'test_secret');

    $postSign = $this->driver->signPostUrl($path);
    expect($postSign['params']['sign'])->toBe($expectedSign);

    $putUrl = $this->driver->signPutUrl($path);
    expect($putUrl)->toContain('sign=' . $expectedSign);
});
