<?php
namespace Logioniz\React\Memcached;

use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

interface SerializerInterface
{
    public function serialize ($value) : array;
    public function unserialize (int $flags, string $value);
}
