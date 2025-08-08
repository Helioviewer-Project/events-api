<?php

declare(strict_types=1);

namespace Helioviewer\EventsApi\Cache;

use Psr\SimpleCache\CacheInterface;
use Redis;

/**
 * Redis implementation of PSR-16 Simple Cache Interface
 *
 * @package    Helioviewer\EventsApi\Cache
 * @author     Kasim Necdet Percinel <kasim.n.percinel@nasa.gov>
 * @since      1.0.0
 */
class RedisCache implements CacheInterface
{
    private Redis $redis;
    private string $prefix;

    public function __construct(Redis $redis, string $prefix = 'hv:events-api:harp:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    /**
     * Fetches a value from the cache.
     *
     * @param string $key The unique key of this item in the cache.
     * @param mixed $default Default value to return if the key does not exist.
     * @return mixed The value of the item from the cache, or $default in case of cache miss.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $prefixedKey = $this->prefix . $key;
        $value = $this->redis->get($prefixedKey);
        
        if ($value === false) {
            return $default;
        }
        
        return unserialize($value);
    }

    /**
     * Persists data in the cache, uniquely referenced by a key with an optional expiration TTL time.
     *
     * @param string $key The key of the item to store.
     * @param mixed $value The value of the item to store. Must be serializable.
     * @param null|int|\DateInterval $ttl Optional. The TTL value of this item.
     * @return bool True on success and false on failure.
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $prefixedKey = $this->prefix . $key;
        $serializedValue = serialize($value);
        
        if ($ttl === null) {
            return $this->redis->set($prefixedKey, $serializedValue);
        }
        
        if ($ttl instanceof \DateInterval) {
            $ttl = (new \DateTime())->add($ttl)->getTimestamp() - time();
        }
        
        return $this->redis->setex($prefixedKey, $ttl, $serializedValue);
    }

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete.
     * @return bool True if the item was successfully removed. False if there was an error.
     */
    public function delete(string $key): bool
    {
        $prefixedKey = $this->prefix . $key;
        return $this->redis->del($prefixedKey) > 0;
    }

    /**
     * Wipes clean the entire cache's keys.
     *
     * @return bool True on success and false on failure.
     */
    public function clear(): bool
    {
        try {
            $keys = $this->redis->keys($this->prefix . '*');
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtains multiple cache items by their unique keys.
     *
     * @param iterable $keys A list of keys that can obtained in a single operation.
     * @param mixed $default Default value to return for keys that do not exist.
     * @return iterable A list of key => value pairs.
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /**
     * Persists a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable $values A list of key => value pairs for a multiple-set operation.
     * @param null|int|\DateInterval $ttl Optional. The TTL value of this item.
     * @return bool True on success and false on failure.
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Deletes multiple cache items in a single operation.
     *
     * @param iterable $keys A list of string-based keys to be deleted.
     * @return bool True if the items were successfully removed. False if there was an error.
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $prefixedKeys = [];
        foreach ($keys as $key) {
            $prefixedKeys[] = $this->prefix . $key;
        }
        
        try {
            $this->redis->del($prefixedKeys);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Determines whether an item is present in the cache.
     *
     * @param string $key The cache item key.
     * @return bool
     */
    public function has(string $key): bool
    {
        $prefixedKey = $this->prefix . $key;
        return $this->redis->exists($prefixedKey) > 0;
    }
}