<?php
namespace Logioniz\React\Memcached;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

class Client
{
    private $promise;
    private $loop;
    private $uri;
    private $data;
    private $queue;
    private $serializer;

    public function __construct(string $uri, LoopInterface $loop, ?SerializerInterface $serializer = null)
    {
        $this->uri = $uri;
        $this->loop = $loop;
        $this->data = '';
        $this->queue = [];
        $this->serializer = $serializer ?? new Serializer();
    }

    public function __call($name, $args)
    {
        $that = $this;

        if ($this->promise === null) {
            $connector = new Connector($this->loop);
            $this->promise = $connector->connect($this->uri)->then(
                function (ConnectionInterface $stream) use ($that) {
                    $stream->on('close', function () use ($that) {
                        $that->promise = null;
                    });

                    $stream->on('data', function ($chunk) use ($that) {
                        // echo '<<<< ' . '"' . $chunk . '"' . PHP_EOL;
                        $that->data .= $chunk;
                        $that->parse();
                    });

                    return $stream;
                }
            );
        }

        return $this->promise->then(
            function (ConnectionInterface $stream) use ($that, $name, $args) {
                $request = new Request($name, $args, $this->serializer);
                array_push($this->queue, $request);

                // echo '>>> ' . '"' . $request->message . '"' . PHP_EOL;
                $stream->write($request->message);

                return $request->promise();
            }
        );
    }

    private function parse()
    {
        while (count($this->queue) && $this->queue[0]->noreply) {
            array_shift($this->queue);
        }

        if (count($this->queue) === 0)
            throw new \Exception("Recieve response for no request", 1);

        $request = $this->queue[0];
        $response = new Response($request, $this->serializer);

        if (!$response->parseResponse($this->data))
            return;

        array_shift($this->queue);

        $request->resolve($response->response);
    }
}
