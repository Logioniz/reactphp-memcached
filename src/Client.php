<?php
namespace Logioniz\React\Memcached;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Promise\PromiseInterface;
use Logioniz\React\Memcached\Exception\NoRequestException;
use Evenement\EventEmitter;

class Client extends EventEmitter
{
    private $promise;
    private $loop;
    private $uri;
    private $data;
    private $queue;
    private $serializer;
    private $options;
    private $isWatch;
    private $debug;

    public function __construct(string $uri, LoopInterface $loop, array $options = [], ?SerializerInterface $serializer = null)
    {
        $this->uri = $uri;
        $this->loop = $loop;
        $this->data = '';
        $this->queue = [];
        $this->options = $options;
        $this->isWatch = false;
        $this->debug = false;
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
                        if ($that->debug) fwrite(STDERR, "<<< \"{$chunk}\"" . PHP_EOL);
                        if ($that->isWatch) {
                            $that->emit('data', [$chunk]);
                            return;
                        }
                        $that->data .= $chunk;
                        $that->parse();
                    });

                    return $stream;
                },
                function ($error) use ($that) {
                    $that->promise = null;
                    throw $error;
                }
            );
        }

        return $this->promise->then(
            function (ConnectionInterface $stream) use ($that, $name, $args) {

                try{
                    $request = new Request($that->loop, $name, $args, $that->serializer);
                } catch (\Exception $e) {
                    if ($that->debug) fwrite(STDERR, $e);
                    return React\Promise\reject($e);
                }

                if ($request->isValid()) {
                    array_push($that->queue, $request);
                    $stream->write($request->message);
                    if ($that->debug) fwrite(STDERR, ">>> \"{$request->message}\"" . PHP_EOL);
                }

                return $request->promise();
            }
        );
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
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

            if (count($this->queue) === 0) {
                $this->data = '';
                return;
            }

            $request = $this->queue[0];

            $exception = null;
            try {
                $response = Response::parseResponse($request, $this->serializer, $this->data);
                if ($response === null) return;
            } catch (\Exception $e) {
                $exception = $e;
            }

            array_shift($this->queue);

            $exception ? $request->reject($e) : $request->resolve($response->message);

            if ($request->command === 'watch') $this->isWatch = true;

            $prevDataLength = $dataLength;
            $dataLength = strlen($this->data);
        }
    }
}
