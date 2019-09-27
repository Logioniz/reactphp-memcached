<?php

namespace Logioniz\Tests\React\Memcached;

use React\EventLoop\StreamSelectLoop;
use Logioniz\React\Memcached\Client;
use React\Promise\Deferred;

class FunctionalTest extends TestCase
{
    private $loop;
    private $uri;
    private $client;

    protected function setUp(): void
    {
        $this->uri = getenv('MEMCACHED_URI');
        if ($this->uri === false) {
            $this->markTestSkipped('No MEMCACHED_URI environment variable given');
        }
        $this->loop = new StreamSelectLoop();
        $this->client = new Client($this->uri, $this->loop);
    }

    /**
     * @dataProvider setDataProvider
     */
    public function testSet($key, $value): void
    {
        $that = $this;
        $this->client->set($key, $value)
            ->then($this->expectCallableOnce());
        $this->client->get($key)
            ->then($this->expectCallableOnceWith($value))
            ->then(function () use ($that) {
                $that->loop->stop();
            });
        $this->loop->run();
    }

    /**
     * @dataProvider getManyDataProvider
     */
    public function testGetMany($k1, $v1, $k2, $v2, $k3, $v3): void
    {
        $that = $this;
        $this->client->set($k1, $v1)
            ->then($this->expectCallableOnce());
        $this->client->set($k2, $v2)
            ->then($this->expectCallableOnce());
        $this->client->set($k3, $v3)
            ->then($this->expectCallableOnce());

        $this->client->get($k1, $k2, $k3)
            ->then($this->expectCallableOnceWith([
                    $k1 => $v1,
                    $k2 => $v2,
                    $k3 => $v3
            ]))
            ->then(function () use ($that) {
                $that->loop->stop();
            });
        $this->loop->run();
    }

    public function testSetNoreply(): void
    {
        $that = $this;
        $this->client->set('x', 'test', 0, true)
            ->then($this->expectCallableOnceWith(null))
            ->then(function () use ($that) {
                $that->loop->stop();
            });
        $this->loop->run();
    }

    /**
     * @dataProvider chainingDataProvider
     */
    public function testChainging($k1, $v1, $k2, $v2): void
    {
        $that = $this;
        $this->client->set($k1, $v1)
            ->then(function ($response) use ($that, $k1) {
                $that->assertEqualsInLoop('STORED', $response);
                return $that->client->get($k1);
            })
            ->then(function ($response) use ($that, $v1) {
                $that->assertEqualsInLoop($v1, $response);
                $d = new Deferred();
                $that->loop->addTimer(0.1, function () use ($d) {
                    $d->resolve();
                });
                return $d->promise();
            })
            ->then(function () use ($that, $k2, $v2) {
                return $that->client->set($k2, $v2);
            })
            ->then(function ($response) use ($that, $k2) {
                $that->assertEqualsInLoop('STORED', $response);
                return $that->client->get($k2);
            })
            ->then(function ($response) use ($that, $v2) {
                $that->assertEqualsInLoop($v2, $response);
                $d = new Deferred();
                $that->loop->futureTick(function () use ($d) {
                    $d->resolve();
                });
                return $d->promise();
            })
            ->then(function () use ($that) {
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    /**
     * @dataProvider wrongRequestParamsDataProvider
     */
    public function testWrongRequestParams($k, $v, $e): void
    {
        $that = $this;

        $params = [$k];
        if (!is_null($v)) array_push($params, $v);
        if (!is_null($e)) array_push($params, $e);

        // $this->client->set($k, $v)
        call_user_func_array([$this->client, 'set'], $params)
            ->otherwise($this->expectCallableOnce())
            ->then(function ($x) use ($that) {
                $that->loop->stop();
            });
        $this->loop->run();
    }

    /**
     * @dataProvider casDataProvider
     */
    public function testCAS($k, $v1, $v2, $v3): void
    {
        $that = $this;
        $this->client->set($k, $v1)
            ->then($this->expectCallableOnceWith('STORED'));
        $this->client->gets($k)
            ->then(function ($response) use ($that, $k, $v1, $v2) {
                $that->assertIsIntInLoop($response['cas']);
                $that->assertEqualsInLoop($v1, $response['value']);
                return $that->client->cas($k, $v2, 0, $response['cas']);
            })
            ->then(function ($response) use ($that, $k) {
                $that->assertEqualsInLoop('STORED', $response);
                return $that->client->gets($k);
            })
            ->then(function ($response) use ($that, $k, $v2, $v3) {
                $that->assertIsIntInLoop($response['cas']);
                $that->assertEqualsInLoop($v2, $response['value']);
                return $that->client->cas($k, $v3, 0, $response['cas'] - 1);
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('EXISTS', $response);
                return $that->client->cas('not_exists', 'test', 0, 123);
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('NOT_FOUND', $response);
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    public function testAddReplaceAppendPrepend(): void
    {
        $that = $this;
        $this->client->add('test', 'value1')
            ->then(function ($response) use ($that) {
                $that->assertContainsInLoop($response, ['STORED', 'NOT_STORED']);
                return $that->client->replace('test', 'value2');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('STORED', $response);
                return $that->client->append('test', 'suffix');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('STORED', $response);
                return $that->client->prepend('test', 'prefix');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('STORED', $response);
                return $that->client->get('test');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('prefixvalue2suffix', $response);
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    public function testDelete(): void
    {
        $that = $this;
        $this->client->set('test', 'value')
            ->then(function ($response) use ($that) {
                $that->assertContainsInLoop($response, ['STORED', 'NOT_STORED']);
                return $that->client->delete('test');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('DELETED', $response);
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    public function testIncrementDecrement(): void
    {
        $that = $this;
        $this->client->set('x', 3)
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('STORED', $response);
                return $this->client->incr('x', 4);
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('7', $response);
                return $this->client->decr('x', 2);
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('5', $response);
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    public function testTouch(): void
    {
        $that = $this;
        $this->client->set('x', 'test string')
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('STORED', $response);
                return $this->client->touch('x', 2);
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('TOUCHED', $response);
                $d = new Deferred();
                $this->loop->addTimer(0.5, function () use ($d, $that) {
                    $d->resolve();
                });
                return $d->promise();
            })
            ->then(function ($response) use ($that) {
                return $this->client->get('x');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('test string', $response);
                $d = new Deferred();
                $this->loop->addTimer(1.5, function () use ($d, $that) {
                    $d->resolve();
                });
                return $d->promise();
            })
            ->then(function ($response) use ($that) {
                return $this->client->get('x');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop(null, $response);
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    public function testGAT(): void
    {
        $that = $this;
        $this->client->set('test', 'value', 10)
            ->then(function ($response) use ($that) {
                $that->assertContainsInLoop($response, ['STORED', 'NOT_STORED']);
                return $that->client->gat(20, 'test');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('value', $response);
                return $that->client->gats(30, 'test');
            })
            ->then(function ($response) use ($that) {
                $that->assertIsIntInLoop($response['cas']);
                $that->assertEqualsInLoop('value', $response['value']);
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    public function testFlushAll(): void
    {
        $that = $this;
        $this->client->set('test', 'value')
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('STORED', $response);
                return $that->client->flush_all();
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop('OK', $response);
                return $that->client->get('test');
            })
            ->then(function ($response) use ($that) {
                $that->assertEqualsInLoop(null, $response);
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    public function testStats(): void
    {
        $that = $this;
        $this->client->stats()
            ->then(function ($response) use ($that) {
                $that->assertIsArrayInLoop($response);
                foreach ($response as $r) {
                    $that->assertStringStartsWithInLoop('STAT', $r);
                }
                $that->loop->stop();
            });
        $this->loop->run();

        if (!empty($this->exceptions)) throw $this->exceptions[0];
    }

    protected function tearDown(): void
    {
        $this->client->close();
    }

    public function setDataProvider(): array
    {
        return [
            ['hash', ['test of hash' => 'test', 'some var' => 'some value', 0 => 123, 1 => 'another test']],
            ['string', 'string test'],
            ['0', 0],
            ['1', 123],
            ['null', null]
        ];
    }

    public function getManyDataProvider(): array
    {
        return [
            ['var1', ['k' => 'v', 0 => 1, 2 => 3], 'var2', '1234', 'var3', 0],
            ['0', [], '1', 0, '2', null],
            ['k1', null, 'k2', null, 'k3', null]
        ];
    }

    public function chainingDataProvider(): array
    {
        return [
            ['0', 'value1', '0', 'value2'],
            ['null', 1234, 'null', 'test'],
            ['1', 123, 'a', null]
        ];
    }

    public function casDataProvider(): array
    {
        return [
            ['0', 'value1', 'value2', 'value3'],
            ['null', null, 0, 'string']
        ];
    }

    public function wrongRequestParamsDataProvider(): array
    {
        return [
            ['test with whitespace', 'value', null],
            ["test\twith\twhitespace", 'value', null],
            ["test\nwith\nwhitespace", 'value', null],
            [str_repeat('0', 251), 'value', null],
            ['test', 'value', 'asd'],
            ['', 'value', 0],
            [null, 'value', 0],
            ['test', null, null],
            ['test', '', true]
        ];
    }
}
