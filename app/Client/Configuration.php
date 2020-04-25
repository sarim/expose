<?php

namespace App\Client;

class Configuration
{
    /** @var string */
    protected $host;

    /** @var int */
    protected $port;

    /** @var string|null */
    protected $auth;

    public function __construct(string $host, int $port, ?string $auth = null)
    {
        $this->host = $host;

        $this->port = $port;

        $this->auth = $auth;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function auth(): ?string
    {
        return $this->auth;
    }

    public function port(): int
    {
        return intval($this->port);
    }
}
