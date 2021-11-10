<?php

namespace App\Server\LoggerRepository;

use App\Contracts\LoggerRepository;
use Clue\React\SQLite\DatabaseInterface;
use React\Promise\PromiseInterface;

class NullLogger implements LoggerRepository
{
    public function logSubdomain($authToken, $subdomain)
    {
        // noop
    }

    public function getLogsBySubdomain($subdomain): PromiseInterface
    {
        return \React\Promise\resolve([]);
    }

    public function getLogs(): PromiseInterface
    {
        return \React\Promise\resolve([]);
    }
}
