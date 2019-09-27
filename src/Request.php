<?php
namespace Logioniz\React\Memcached;

use React\Promise\PromiseInterface;
use React\Promise\PromisorInterface;
use React\EventLoop\LoopInterface;

use Logioniz\React\Memcached\Exception\InvalidParamException;

class Request implements PromisorInterface
{
    const LINE_END = "\r\n";

    public $command;
    public $noreply;
    public $message;
    public $oneKey;
    public $loop;

    private $deferred;
    private $serializer;
    private $exception;

    public function __construct(LoopInterface $loop, string $command, array $args, SerializerInterface $serializer)
    {
        $this->loop = $loop;
        $this->command = strtolower($command);
        $this->serializer = $serializer;
        $this->noreply = false;
        $this->oneKey = false;
        $this->deferred = new \React\Promise\Deferred();
        $this->buildCommand($args);
    }

    protected function buildCommand(array $args)
    {
        if (in_array($this->command, ['set', 'cas', 'add', 'replace', 'append', 'prepend'])) {
            $this->buildSetCommand($args);
        } elseif (in_array($this->command, ['get', 'gets', 'gat', 'gats'])) {
            $this->buildGetCommand($args);
        } elseif ($this->command === 'delete') {
            $this->buildDeleteCommand($args);
        } elseif (in_array($this->command, ['incr', 'decr'])) {
            $this->buildIncrementCommand($args);
        } elseif ($this->command === 'touch') {
            $this->buildTouchCommand($args);
        } else {
            $this->buildOtherCommand($args);
        }
    }

    protected function buildSetCommand(array $args): void
    {
        if (!$this->checkNumberOfArguments(count($args), 2)) return;
        if ($this->command === 'cas' && !$this->checkNumberOfArguments(count($args), 4)) return;

        list($key, $value, $exptime) =
            [array_shift($args), array_shift($args), array_shift($args) ?? 0];

        if (!$this->checkKeyParameter($key)) return;
        if (!$this->checkExptimeParameter($exptime)) return;

        $cas = null;
        if ($this->command == 'cas' && !empty($args)) {
            $cas = array_shift($args);
            if (!$this->checkCASParameter($cas)) return;
        }

        $this->noreply = false;
        if (!empty($args)) {
            $this->noreply = array_shift($args);
            if (!$this->checkNoreplyParameter($this->noreply)) return;
        }

        $noreply = $this->noreply ? 'noreply' : '';
        if ($this->noreply) $this->resolveNonblocking();

        list($flags, $value) = $this->serializer->serialize($value);

        $params = [$this->command, $key, $flags, $exptime, strlen($value)];

        if ($this->command  === 'cas') array_push($params, $cas);
        if ($this->noreply) array_push($params, 'noreply');

        $this->message = implode(' ', $params) . self::LINE_END . $value . self::LINE_END;
    }

    protected function buildGetCommand(array $args): void
    {
        if (!$this->checkNumberOfArguments(count($args), 1)) return;

        if (in_array($this->command, ['gat', 'gats']) &&
            !$this->checkNumberOfArguments(count($args), 2)) return;

        $params = [$this->command];

        $exptime = null;
        if (in_array($this->command, ['gat', 'gats'])) {
            $exptime = array_shift($args);
            if (!$this->checkExptimeParameter($exptime)) return;
            array_push($params, $exptime);
        }

        foreach ($args as $key) {
            if (!$this->checkKeyParameter($key)) return;
        }

        $this->oneKey = count($args) > 1 ? false : true;
        $this->message = implode(' ', $params) . ' ' . implode(' ', $args) . self::LINE_END;
    }

    protected function buildDeleteCommand(array $args): void
    {
        if (!$this->checkNumberOfArguments(count($args), 1)) return;

        $key = $args[0];
        if (!$this->checkKeyParameter($key)) return;

        $this->noreply = $args[1] ?? false;
        if (!$this->checkNoreplyParameter($this->noreply)) return;

        $params = [$this->command, $key];
        if ($this->noreply) {
            array_push($params, 'noreply');
            $this->resolveNonblocking();
        }

        $this->message = implode(' ', $params) . self::LINE_END;
    }

    protected function buildIncrementCommand(array $args): void
    {
        if (!$this->checkNumberOfArguments(count($args), 2)) return;

        list($key, $value, $this->noreply) = [$args[0], $args[1], $args[2] ?? false];

        if (!$this->checkKeyParameter($key)) return;
        if (!$this->checkIncrementValueParameter($value)) return;
        if (!$this->checkNoreplyParameter($this->noreply)) return;

        $params = [$this->command, $key, $value];
        if ($this->noreply) {
            $this->resolveNonblocking();
            array_push($params, 'noreply');
        }

        $this->message = implode(' ', $params) . self::LINE_END;
    }

    protected function buildTouchCommand(array $args): void
    {
        if (!$this->checkNumberOfArguments(count($args), 2)) return;

        list($key, $exptime, $this->noreply) = [$args[0], $args[1], $args[2] ?? false];

        if (!$this->checkKeyParameter($key)) return;
        if (!$this->checkExptimeParameter($exptime)) return;
        if (!$this->checkNoreplyParameter($this->noreply)) return;

        $params = [$this->command, $key, $exptime];
        if ($this->noreply) {
            $this->resolveNonblocking();
            array_push($params, 'noreply');
        }

        $this->message = implode(' ', $params) . self::LINE_END;
    }

    protected function buildOtherCommand(array $args): void
    {
        $this->message = $this->command . ' ' . implode(' ', $args) . self::LINE_END;
    }

    public function isValid(): bool
    {
        return empty($this->exception);
    }

    public function promise(): PromiseInterface
    {
        return $this->deferred->promise();
    }

    public function resolve($value = null): void
    {
        $this->deferred->resolve($value);
    }

    public function resolveNonblocking(): void
    {
        $that = $this;
        $this->loop->futureTick(function () use ($that) {
            $that->resolve();
        });
    }

    public function reject(\Exception $exception): void
    {
        $this->deferred->reject($exception);
    }

    public function rejectNonblocking(): void
    {
        $that = $this;
        $this->loop->futureTick(function () use ($that) {
            $that->reject($that->exception);
        });
    }

    protected function checkKeyParameter($key): bool
    {
        $exception = null;

        if (strlen($key) == 0) {
            $exception = new InvalidParamException("Parameter \"key\" of \"{$this->command}\" command must be not empty");
        }

        if (strlen($key) > 250) {
            $exception = new InvalidParamException("Parameter \"key\" of \"{$this->command}\" command must be less than 250 symbols");
        }

        if (preg_match('/\s/', $key)) {
            $exception = new InvalidParamException("Parameter \"key\" of \"{$this->command}\" command should not contain whitespace");
        }

        if (!$exception) return true;

        $this->exception = $exception;
        $this->rejectNonblocking();
        return false;
    }

    protected function checkExptimeParameter($exptime): bool
    {
        if (is_numeric($exptime)) return true;

        $this->exception = new InvalidParamException("Parameter \"exptime\" of \"{$this->command}\" command must consists of digits");
        $this->rejectNonblocking();
        return false;
    }

    protected function checkNumberOfArguments($current, $min): bool
    {
        if ($current >= $min) return true;

        $this->exception = new InvalidParamException(
            "Command \"{$this->command}\" must takes at least {$min} parameter" . ($min > 1 ? 's' : ''));
        $this->rejectNonblocking();
        return false;
    }

    protected function checkCASParameter($cas): bool
    {
        if (is_numeric($cas)) return true;

        $this->exception = new InvalidParamException("Parameter \"cas\" of \"{$this->command}\" command must consists of digits");
        $this->rejectNonblocking();
        return false;
    }

    protected function checkNoreplyParameter($noreply): bool
    {
        if (gettype($noreply) === 'boolean') return true;

        $this->exception = new InvalidParamException("Parameter \"noreply\" of \"{$this->command}\" command must be boolean");
        $this->rejectNonblocking();
        return false;
    }

    protected function checkIncrementValueParameter($value): bool
    {
        if (is_numeric($value)) return true;

        $this->exception = new InvalidParamException("Parameter \"value\" of \"{$this->command}\" command must consists of digits");
        $this->rejectNonblocking();
        return false;
    }
}
