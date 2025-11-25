<?php

namespace Helioviewer\EventsApi\Utils;

use Psr\Container\ContainerInterface;
use ArrayAccess;

/**
 * Simple container implementation that wraps ArrayObject/ArrayAccess
 * and implements PSR-11 ContainerInterface with singleton pattern
 */
class Container implements ContainerInterface, ArrayAccess
{
    private static ?Container $instance = null;
    private $services;

    private function __construct($services = [])
    {
        $this->services = $services;
    }

    /**
     * Set services and create/update singleton instance
     *
     * @param array $services Array of service name => service instance
     * @return void
     */
    public static function setServices(array $services): void
    {
        self::$instance = new self($services);
    }

    /**
     * Get singleton instance
     *
     * @return Container
     * @throws \RuntimeException If container hasn't been initialized
     */
    public static function getInstance(): Container
    {
        if (self::$instance === null) {
            throw new \RuntimeException("Container has not been initialized. Call Container::setServices() first.");
        }
        return self::$instance;
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