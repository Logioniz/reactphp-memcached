<?php
namespace Logioniz\React\Memcached;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Promise\PromiseInterface;
use Logioniz\React\Memcached\Exception\NoRequestException;

class Client
{
    private $promise;
    private $loop;
    private $uri;
    private $data;
    private $queue;
    private $serializer;
    private $options;

    public function __construct(string $uri, LoopInterface $loop, array $options = [], ?SerializerInterface $serializer = null)
    {
        $this->uri = $uri;
        $this->loop = $loop;
        $this->data = '';
        $this->queue = [];
        $this->options = $options;
        $this->serializer = $serializer ?? new Serializer();
    }

    public function __call($name, $args): PromiseInterface
    {
        $that = $this;

        if ($this->promise === null) {
            $connector = new Connector($this->loop, $this->options);
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
                $request = new Request($name, $args, $that->serializer);
                array_push($that->queue, $request);

                // echo '>>> ' . '"' . $request->message . '"' . PHP_EOL;
                $stream->write($request->message);

                return $request->promise();
            }
        );
    }

    public function close(): void
    {
        if ($this->promise == null) return;

        $promise = $this->promise;
        $promise->then(function (ConnectionInterface $stream) use ($promise) {
            $stream->close();
            $promise->cancel();
        });

        $this->promise = null;
    }

    private function parse(): void
    {
        $prevDataLength = 0;
        $dataLength = strlen($this->data);

        // there can be many responses at one answer
        while ($dataLength > 0 and $dataLength != $prevDataLength) {
            while (count($this->queue) && $this->queue[0]->noreply) {
                array_shift($this->queue);
            }

            if (count($this->queue) === 0)
                throw new NoRequestException('Recieve response for no request');

            $request = $this->queue[0];
            $response = new Response($request, $this->serializer);

            try {
                if (!$response->parseResponse($this->data)) return;
            } catch (\Exception $e) {
                $request->reject($e);
            }

            array_shift($this->queue);

            $request->resolve($response->response);

            $prevDataLength = $dataLength;
            $dataLength = strlen($this->data);
        }
    }
}
