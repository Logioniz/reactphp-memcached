<?php
namespace Logioniz\React\Memcached;

use React\Promise\PromiseInterface;
use React\Promise\PromisorInterface;

use Logioniz\React\Memcached\Exception\InvalidParamsException;

class Request implements PromisorInterface
{
    public $command;
    public $noreply;
    public $message;
    public $info;

    private $deferred;
    private $serializer;

    public function __construct(string $command, array $args, SerializerInterface $serializer)
    {
        $this->command = strtolower($command);
        $this->serializer = $serializer;
        $this->noreply = false;
        $this->info = new \StdClass();

        $this->deferred = new \React\Promise\Deferred();

        if ($this->command == 'set') {
            if (count($args) < 2)
                $this->reject(new InvalidParamException('Set command must takes at least two parameters'));

            list($key, $value) = [$args[0], $args[1]];
            $exptime = count($args) > 2 ? $args[2] : 0;
            $this->noreply = count($args) > 3 ? $args[3] : false;
            if ($this->noreply)
                $this->resolve();
            list($flags, $value) = $this->serializer->serialize($value);
            $params = [$command, $key, $flags, $exptime, strlen($value), $this->noreply ? 'noreply' : ''];
            $this->message = implode(' ', $params) . "\r\n" . $value . "\r\n";
        } elseif (in_array($this->command, ['get', 'gets'])) {
            if (count($args) < 1)
                $this->reject(new InvalidParamException('Get command must takes at least one parameter'));

            $this->info->oneKey = count($args) > 1 ? false : true;
            $this->message = $command . ' ' . implode(' ', $args) . "\r\n";
        }
    }

    public function promise(): PromiseInterface
    {
        return $this->deferred->promise();
    }

    public function resolve($value = null): void
    {
        $this->deferred->resolve($value);
    }

    public function reject(\Exception $e): void
    {
        $this->deferred->reject($e);
    }
}
