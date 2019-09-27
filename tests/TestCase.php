<?php

namespace Logioniz\Tests\React\Memcached;

use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    protected $exceptions = [];

    protected function expectCallableOnceWith($argument)
    {
        $mock = $this->getMockCallback();
        $mock->expects($this->once())
            ->method('__invoke')
            ->with($argument);
        return $mock;
    }

    protected function expectCallableOnce()
    {
        $mock = $this->getMockCallback();
        $mock->expects($this->once())
            ->method('__invoke');
        return $mock;
    }

    protected function getMockCallback()
    {
        return $this->getMockBuilder('stdClass')
                    ->setMethods(['__invoke'])
                    ->getMock();
    }

    protected function assertSameInLoop($expected, $var, string $comment = ''): void
    {
        $this->assertInLoop('assertSame', [$expected, $var, $comment]);
    }

    protected function assertEqualsInLoop($expected, $var, string $comment = ''): void
    {
        $this->assertInLoop('assertEquals', [$expected, $var, $comment]);
    }

    protected function assertIsIntInLoop($expected, string $comment = ''): void
    {
        $this->assertInLoop('assertIsInt', [$expected, $comment]);
    }

    protected function assertContainsInLoop($expected, $arr, string $comment = ''): void
    {
        $this->assertInLoop('assertContains', [$expected, $arr, $comment]);
    }

    protected function assertIsArrayInLoop($expected, string $comment = ''): void
    {
        $this->assertInLoop('assertIsArray', [$expected, $comment]);
    }

    protected function assertStringStartsWithInLoop(string $prefix, string $str, string $comment = ''): void
    {
        $this->assertInLoop('assertStringStartsWith', [$prefix, $str, $comment]);
    }

    protected function assertInLoop(string $method, array $params): void
    {
        try {
            call_user_func_array([$this, $method], $params);
        } catch (\Exception $e) {
            array_push($this->exceptions, $e);
        }
    }
}
