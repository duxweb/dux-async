<?php

use Aws\Command;
use Aws\S3\S3Client;
use Aws\Result;
use Aws\MockHandler;
use Core\Storage\Drivers\S3Driver;
use GuzzleHttp\Psr7;

beforeEach(function () {
    $this->config = [
        'bucket' => 'test-bucket',
        'domain' => 'https://test.example.com',
        'endpoint' => 'localhost',
        'region' => 'us-east-1',
        'ssl' => true,
        'access_key' => 'test-key',
        'secret_key' => 'test-secret',
    ];

    // 创建 Mock Handler
    $this->mockHandler = new MockHandler();
    
    // 创建模拟的 S3 客户端
    $s3Client = new S3Client([
        'version' => 'latest',
        'region'  => 'us-east-1',
        'credentials' => [
            'key'    => 'test-key',
            'secret' => 'test-secret',
        ],
        'handler' => $this->mockHandler,
    ]);

    $this->driver = new S3Driver($this->config, $s3Client);
});

test('basic file operations', function () {
    $path = 'test/file.txt';
    $content = 'Hello S3';

    // 模拟 putObject 响应
    $this->mockHandler->append(new Result([]));

    // 模拟 doesObjectExist 响应
    $this->mockHandler->append(new Result(['@metadata' => ['statusCode' => 200]]));

    // 模拟 getObject 响应
    $stream = Psr7\Utils::streamFor($content);
    $this->mockHandler->append(new Result([
        'Body' => $stream,
        'ContentLength' => strlen($content)
    ]));

    // 模拟 headObject 响应
    $this->mockHandler->append(new Result([
        'ContentLength' => strlen($content)
    ]));

    // 模拟 deleteObject 响应
    $this->mockHandler->append(new Result([]));

    // 修改这里：模拟最后一次 doesObjectExist 响应，抛出 S3 异常
    $this->mockHandler->append(function (Command $cmd) {
        throw new \Aws\S3\Exception\S3Exception(
            'The specified key does not exist.',
            $cmd,
            ['code' => 'NoSuchKey', 'statusCode' => 404]
        );
    });

    expect($this->driver->write($path, $content))->toBeTrue();
    expect($this->driver->exists($path))->toBeTrue();
    expect($this->driver->read($path))->toBe($content);
    expect($this->driver->size($path))->toBe(strlen($content));
    expect($this->driver->delete($path))->toBeTrue();
    expect($this->driver->exists($path))->toBeFalse();
});

test('stream operations', function () {
    $path = 'test/stream.txt';
    $content = 'Stream Test Content';

    // 模拟 putObject 响应
    $this->mockHandler->append(new Result([]));

    // 模拟 getObject 响应
    $stream = Psr7\Utils::streamFor($content);
    $this->mockHandler->append(new Result([
        'Body' => $stream
    ]));

    $inputStream = fopen('php://temp', 'r+');
    fwrite($inputStream, $content);
    rewind($inputStream);

    expect($this->driver->writeStream($path, $inputStream))->toBeTrue();
    fclose($inputStream);

    $readStream = $this->driver->readStream($path);
    expect($readStream)->toBeResource();
    expect(stream_get_contents($readStream))->toBe($content);
    fclose($readStream);
});

test('url generation', function () {
    $path = 'test/url.txt';

    // 模拟 putObject 响应
    $this->mockHandler->append(new Result([]));

    // 模拟 getObjectUrl 响应
    $this->mockHandler->append(new Result([
        'ObjectURL' => "https://test.example.com/{$path}"
    ]));

    expect($this->driver->write($path, 'content'))->toBeTrue();
    
    $publicUrl = $this->driver->publicUrl($path);
    expect($publicUrl)->toBeString()
        ->toContain($path);
});

test('signed urls', function () {
    $path = 'test/signed-' . uniqid() . '.txt';
    
    // 测试POST签名
    $postSign = $this->driver->signPostUrl($path);
    expect($postSign)->toBeArray()
        ->toHaveKeys(['url', 'params'])
        ->and($postSign['params'])->toHaveKey('X-Amz-Signature');
    
    // 测试PUT签名
    $putUrl = $this->driver->signPutUrl($path);
    expect($putUrl)->toBeString()
        ->toContain('X-Amz-Signature');
});

test('large file operations', function () {
    $path = 'test/large-' . uniqid() . '.dat';
    $size = 6 * 1024 * 1024; // 6MB
    
    // 模拟大文件上传响应
    $this->mockHandler->append(new Result([]));
    
    // 模拟获取文件大小响应
    $this->mockHandler->append(new Result([
        'ContentLength' => $size
    ]));
    
    // 模拟读取响应
    $this->mockHandler->append(new Result([
      'Body' => Psr7\Utils::streamFor(str_repeat('0', $size))
    ]));
    
    // 创建大文件流
    $stream = fopen('php://temp', 'r+');
    $chunk = str_repeat('0', 8192);
    for ($i = 0; $i < $size / 8192; $i++) {
        fwrite($stream, $chunk);
    }
    rewind($stream);
    
    expect($this->driver->writeStream($path, $stream))->toBeTrue();
    fclose($stream);
    
    expect($this->driver->size($path))->toBe($size);
    
    $readStream = $this->driver->readStream($path);
    expect($readStream)->toBeResource();
    $downloadedSize = 0;
    while (!feof($readStream)) {
        $downloadedSize += strlen(fread($readStream, 8192));
    }
    expect($downloadedSize)->toBe($size);
    fclose($readStream);
});

test('driver type', function () {
    expect($this->driver->isLocal())->toBeFalse();
}); 