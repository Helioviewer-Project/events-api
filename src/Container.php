<?php

namespace Helioviewer\EventsApi;

use Psr\Container\ContainerInterface;
use ArrayAccess;

/**
 * Simple container implementation that wraps ArrayObject/ArrayAccess
 * and implements PSR-11 ContainerInterface
 */
class Container implements ContainerInterface, ArrayAccess
{
    private $services;

    public function __construct($services = [])
    {
        $this->services = $services;
    }

    /**
     * PSR-11 ContainerInterface: get
     */
    public function get(string $id)
    {
        if (!$this->has($id)) {
            throw new \Exception("Service '{$id}' not found in container");
        }
        return $this->services[$id];
    }

    /**
     * PSR-11 ContainerInterface: has
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * ArrayAccess: offsetExists
     */
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /**
     * ArrayAccess: offsetGet
     */
    public function offsetGet($offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * ArrayAccess: offsetSet
     */
    public function offsetSet($offset, $value): void
    {
        $this->services[$offset] = $value;
    }

    /**
     * ArrayAccess: offsetUnset
     */
    public function offsetUnset($offset): void
    {
        unset($this->services[$offset]);
    }

    /**
     * Get all services (for backward compatibility)
     */
    public function all(): array
    {
        return $this->services;
    }
}