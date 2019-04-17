<?php
namespace Logioniz\React\Memcached;

class Serializer implements SerializerInterface
{
    public function serialize ($value) : array
    {
        if (is_object($value) || is_array($value))
            return [4, serialize($value)];

        return [0, $value];
    }

    public function unserialize (int $flags, string $value)
    {
        if ($flags == 4)
            return unserialize($value);
        return $value;
    }
}
