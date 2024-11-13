<?php

namespace Core\Cache\Adapter;

use Psr\Cache\CacheItemInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Swoole\Table;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;

class MemoryAdapter implements AdapterInterface, CacheInterface, LoggerAwareInterface, ResettableInterface
{
    use LoggerAwareTrait;

    private Table $table;
    private static \Closure $createCacheItem;

    public function __construct(
        private int $defaultLifetime = 0,
        private int $tableSize = 1024
    ) {
        $this->initTable();
        $this->initCacheItemCreator();
    }

    private function initTable(): void 
    {
        $this->table = new Table($this->tableSize);
        $this->table->column('value', Table::TYPE_STRING, 8192);
        $this->table->column('expiry', Table::TYPE_INT);
        $this->table->create();
    }

    private function initCacheItemCreator(): void
    {
        self::$createCacheItem ??= \Closure::bind(
            static function ($key, $value = null, $isHit = false) {
                $item = new CacheItem();
                $item->key = $key;
                $item->value = $value;
                $item->isHit = $isHit;
                return $item;
            },
            null,
            CacheItem::class
        );
    }

    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        $item = $this->getItem($key);
        if (!$item->isHit()) {
            $save = true;
            $item->set($callback($item, $save));
            if ($save) {
                $this->save($item);
            }
        }
        return $item->get();
    }

    public function getItem(mixed $key): CacheItem
    {
        $row = $this->table->get($key);
        
        if (!$row) {
            return (self::$createCacheItem)($key);
        }
        
        if ($row['expiry'] > 0 && $row['expiry'] <= microtime(true)) {
            $this->deleteItem($key);
            return (self::$createCacheItem)($key);
        }
        
        return (self::$createCacheItem)($key, unserialize($row['value']), true);
    }

    public function getItems(array $keys = []): iterable
    {
        return $this->generateItems($keys);
    }

    public function clear(string $prefix = ''): bool
    {
        if ($prefix === '') {
            $this->table->destroy();
            $this->initTable();
            return true;
        }

        foreach ($this->table as $key => $row) {
            if (str_starts_with($key, $prefix)) {
                $this->table->del($key);
            }
        }
        return true;
    }

    public function delete(string $key): bool
    {
        return $this->deleteItem($key);
    }

    public function deleteItem(mixed $key): bool
    {
        return $this->table->del($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
        }
        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$item instanceof CacheItem) {
            return false;
        }
        
        // 将 CacheItem 转为数组以访问私有属性
        $itemArray = (array) $item;
        $key = $item->getKey();
        $value = $item->get();
        $expiry = $itemArray["\0*\0expiry"] ?? null;  // 获取私有属性 expiry
        
        $now = microtime(true);
        
        // 处理过期时间
        if (null !== $expiry) {
            if (!$expiry) {
                $expiry = PHP_INT_MAX;
            } elseif ($expiry <= $now) {
                $this->deleteItem($key);
                return true;
            }
        } elseif ($this->defaultLifetime > 0) {
            $expiry = $now + $this->defaultLifetime;
        } else {
            $expiry = PHP_INT_MAX;
        }

        dump($expiry);

        return $this->table->set($key, [
            'value' => serialize($value),
            'expiry' => (int)$expiry,
        ]);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }

    public function hasItem(mixed $key): bool
    {
        $row = $this->table->get($key);
        if (!$row) {
            return false;
        }
        
        // 检查是否过期
        if ($row['expiry'] > 0 && $row['expiry'] <= microtime(true)) {
            $this->deleteItem($key);
            return false;
        }
        
        return true;
    }

    public function reset(): void
    {
        $this->clear();
    }

    private function generateItems(array $keys): \Generator
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }
}