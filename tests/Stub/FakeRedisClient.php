<?php

namespace App\Tests\Stub;

/**
 * In-memory drop-in replacement for Predis\Client used during tests.
 *
 * Implements only the methods used by the application so tests run
 * without a live Redis server.
 */
class FakeRedisClient extends \Predis\Client
{
    /** @var array<string, string> */
    private array $store = [];

    /** @var array<string, int> */
    private array $ttl = [];

    /** Suppress Predis\Client constructor (no real connection needed). */
    public function __construct()
    {
        // intentionally empty – do NOT call parent::__construct()
    }

    public function reset(): void
    {
        $this->store = [];
        $this->ttl   = [];
    }

    // ------------------------------------------------------------------ //
    // Methods used by ApiAuthListener + AuthController + TransferService  //
    // ------------------------------------------------------------------ //

    public function get($key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function set($key, $value): mixed
    {
        $this->store[$key] = (string) $value;
        return 'OK';
    }

    public function setex($key, $seconds, $value): mixed
    {
        $this->store[$key] = (string) $value;
        $this->ttl[$key]   = (int) $seconds;
        return 'OK';
    }

    public function exists($key): int
    {
        return isset($this->store[$key]) ? 1 : 0;
    }

    public function del($key): int
    {
        if (isset($this->store[$key])) {
            unset($this->store[$key], $this->ttl[$key]);
            return 1;
        }
        return 0;
    }

    public function incr($key): int
    {
        $val = (int) ($this->store[$key] ?? 0);
        $val++;
        $this->store[$key] = (string) $val;
        return $val;
    }

    public function expire($key, $seconds): int
    {
        if (isset($this->store[$key])) {
            $this->ttl[$key] = (int) $seconds;
            return 1;
        }
        return 0;
    }

    public function ping(): string
    {
        return 'PONG';
    }
}