<?php
namespace Logioniz\React\Memcached;

class Request
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

        if ($this->command == 'set') {
            if (count($args) < 2)
                throw new \Exception("Set command must takes at least two parameters", 1);

            list($key, $value) = [$args[0], $args[1]];
            $exptime = count($args) > 2 ? $args[2] : 0;
            $this->noreply = count($args) > 3 ? $args[3] : false;
            list($flags, $value) = $this->serializer->serialize($value);
            $params = [$command, $key, $flags, $exptime, strlen($value), $this->noreply ? 'noreply' : ''];
            $this->message = implode(' ', $params) . "\r\n" . $value . "\r\n";
        } elseif (in_array($this->command, ['get', 'gets'])) {
            if (count($args) < 1)
                throw new \Exception("Get command must takes at least one parameter", 1);

            $this->info->oneKey = count($args) > 1 ? false : true;
            $this->message = $command . ' ' . implode(' ', $args) . "\r\n";
        }

        $this->deferred = new \React\Promise\Deferred();
    }

    public function promise()
    {
        return $this->deferred->promise();
    }

    public function resolve($value)
    {
        $this->deferred->resolve($value);
    }
}
