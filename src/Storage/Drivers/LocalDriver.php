<?php
declare(strict_types=1);

namespace Core\Storage\Drivers;

use Core\Storage\Contracts\StorageInterface;
use Core\Storage\Exceptions\StorageException;

class LocalDriver implements StorageInterface
{
    private string $root;
    private string $domain;
    private string $path;
    private $signCallback;

    public function __construct(array $config, ?callable $signCallback = null)
    {
        $this->root = rtrim($config['root'], '/');
        $this->domain = rtrim($config['domain'], '/');
        $this->path = $config['path'] ?? '';
        $this->signCallback = $signCallback;
    }

    public function write(string $path, string $contents, array $options = []): bool
    {
        $fullPath = $this->root . '/' . $this->getUploadPath($path);
        $dir = dirname($fullPath);
        
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new StorageException("无法创建目录: {$dir}");
            }
        }
        
        if (file_put_contents($fullPath, $contents) === false) {
            throw new StorageException("写入文件失败: {$path}");
        }
        
        return true;
    }

    public function writeStream(string $path, $resource, array $options = []): bool
    {
        $fullPath = $this->root . '/' . $this->getUploadPath($path);
        $dir = dirname($fullPath);
        
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true)) {
                throw new StorageException("无法创建目录: {$dir}");
            }
        }
        
        $dest = fopen($fullPath, 'wb');
        if ($dest === false) {
            throw new StorageException("无法创建文件: {$path}");
        }
        
        try {
            if (stream_copy_to_stream($resource, $dest) === false) {
                throw new StorageException("写入文件流失败: {$path}");
            }
        } finally {
            fclose($dest);
        }
        
        return true;
    }

    public function read(string $path): string
    {
        $fullPath = $this->root . '/' . $this->getUploadPath($path);
        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            throw new StorageException("读取文件失败: {$path}");
        }
        return $contents;
    }

    public function readStream(string $path)
    {
        $fullPath = $this->root . '/' . $this->getUploadPath($path);
        $stream = @fopen($fullPath, 'rb');
        if ($stream === false) {
            throw new StorageException("打开文件流失败: {$path}");
        }
        return $stream;
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->root . '/' . $this->getUploadPath($path);
        if (!file_exists($fullPath)) {
            return true;
        }
        if (!@unlink($fullPath)) {
            throw new StorageException("删除文件失败: {$path}");
        }
        return true;
    }

    public function size(string $path): int
    {
        $fullPath = $this->root . '/' . $this->getUploadPath($path);
        $size = @filesize($fullPath);
        if ($size === false) {
            throw new StorageException("获取文件大小失败: {$path}");
        }
        return $size;
    }

    public function exists(string $path): bool
    {
        $fullPath = $this->root . '/' . $this->getUploadPath($path);
        return file_exists($fullPath);
    }

    public function publicUrl(string $path): string
    {
        $domain = rtrim($this->domain, '/');
        if ($this->path) {
            $domain = sprintf('%s/%s', $this->domain, $this->path);
        }
        return sprintf('%s/%s', $domain, $path);
    }

    public function privateUrl(string $path, int $expires = 3600): string
    {
        return $this->publicUrl($path);
    }

    public function signPostUrl(string $path): array
    {
        $url = $this->getUploadPath($path);
        $sign = $this->getSign($url);
        
        return [
            'url' => $url,
            'params' => [
                'sign' => $sign,
                'key' => $path,
            ]
        ];
    }

    public function signPutUrl(string $path): string
    {
        $url = $this->getUploadPath($path);
        $sign = $this->getSign($url);
        
        $params = http_build_query([
            'sign' => $sign,
            'key' => $path,
        ]);
        
        return str_contains($url, '?') 
            ? $url . '&' . $params 
            : $url . '?' . $params;
    }

    private function getUploadPath(string $path): string
    {
        if ($this->path) {
            $path = sprintf('%s/%s', $this->path, $path);
        }
        return $path;
    }

    private function getSign(string $path): string
    {
        if ($this->signCallback) {
            return call_user_func($this->signCallback, $path);
        }
        return '';
    }

    public function isLocal(): bool
    {
        return true;
    }
} 