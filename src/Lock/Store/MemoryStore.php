<?php

namespace Core\Lock\Store;

use Symfony\Component\Lock\SharedLockStoreInterface;
use Symfony\Component\Lock\Store\ExpiringStoreTrait;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\InvalidTtlException;

class MemoryStore implements SharedLockStoreInterface
{
    use ExpiringStoreTrait;
    
    private array $locks = [];
    private float $initialTtl;
    
    public function __construct(float $initialTtl = 300.0)
    {
        if ($initialTtl <= 0) {
            throw new InvalidTtlException(sprintf('"%s()" expects a strictly positive TTL. Got %d.', __METHOD__, $initialTtl));
        }
        $this->initialTtl = $initialTtl;
    }
    
    public function save(Key $key): void
    {
        $this->checkNotExpired($key);
        $resource = $this->getKeyResource($key);
        
        if (isset($this->locks[$resource]) && ($this->locks[$resource]['type'] !== null)) {
            throw new LockConflictedException();
        }
        
        $this->locks[$resource] = [
            'type' => 'write',
            'count' => 1,
            'expiry' => microtime(true) + $this->initialTtl
        ];
        $key->reduceLifetime($this->initialTtl);
    }
    
    public function saveRead(Key $key): void
    {
        $this->checkNotExpired($key);
        $resource = $this->getKeyResource($key);
        
        if (isset($this->locks[$resource]) && $this->locks[$resource]['type'] === 'write') {
            throw new LockConflictedException();
        }
        
        if (!isset($this->locks[$resource])) {
            $this->locks[$resource] = ['type' => 'read', 'count' => 0, 'expiry' => 0];
        }
        
        $this->locks[$resource]['count']++;
        $this->locks[$resource]['expiry'] = microtime(true) + $this->initialTtl;
        $key->reduceLifetime($this->initialTtl);
    }
    
    public function delete(Key $key): void
    {
        $resource = $this->getKeyResource($key);
        if (!isset($this->locks[$resource])) {
            return;
        }
        
        if ($this->locks[$resource]['type'] === 'read') {
            if (--$this->locks[$resource]['count'] <= 0) {
                unset($this->locks[$resource]);
            }
        } else {
            unset($this->locks[$resource]);
        }
    }
    
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $resource = $this->getKeyResource($key);
        if (isset($this->locks[$resource])) {
            $this->locks[$resource]['expiry'] = microtime(true) + $ttl;
            $key->reduceLifetime($ttl);
        }
        $this->checkNotExpired($key);
    }
    
    public function exists(Key $key): bool
    {
        $resource = $this->getKeyResource($key);
        if (!isset($this->locks[$resource])) {
            return false;
        }
        
        if ($this->locks[$resource]['expiry'] <= microtime(true)) {
            unset($this->locks[$resource]);
            return false;
        }
        
        return true;
    }
    
    private function getKeyResource(Key $key): string
    {
        return (string) $key;
    }
}