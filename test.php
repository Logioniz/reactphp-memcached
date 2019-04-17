<?php

$loader = require __DIR__ . '/vendor/autoload.php';

$loop = React\EventLoop\Factory::create();


class MySerializer implements Logioniz\React\Memcached\SerializerInterface
{
    public function serialize ($value) : array
    {
        return [10, json_encode($value)];
    }

    public function unserialize (int $flags, string $value)
    {
        if ($flags == 10)
            return json_decode($value);
        return $value;
    }
}

$client = new Logioniz\React\Memcached\Client('127.0.0.1:11211', $loop, new MySerializer());

// $client->set('qwe', 'qwerty')->then(
//     function ($res) use ($client) {
//         echo $res . PHP_EOL;
//         $msg = 'asd message';
//         $client->set('asd', $msg, 0, true);
//         return 1;
//     }
// )->then(
//     function ($res) use ($client) {
//         echo $res . PHP_EOL;

//         return $client->gets('qwe', 'asd');
//     }
// )->then(
//     function ($res) use ($client) {
//         var_dump($res);
//         return $client->get('zxc');
//     }
// )->then(
//     function ($res) use ($loop) {
//         var_dump($res);
//         $loop->stop();
//     }
// )->otherwise(
//     function ($error) use ($loop) {
//         echo $error . PHP_EOL;
//         $loop->stop();
//     }
// );

// $client->set('key', "VALUE qwe 0 3\r\nqwe\r\nEND\r\nVALUE asd 0 3\r\nasd\r\nEND\r\nVALUE zxc 0 3\r\nzxc\r\nEND")
//     ->then(
//         function ($res) use ($client) {
//             return $client->get('key');
//         }
//     )
//     ->then(
//         function ($data) use ($loop) {
//             var_dump($data);
//             $loop->stop();
//         }
//     );

// $client->set('a', str_repeat('a', 1000000))
//         ->then(function () use ($client) {
//             return $client->get('a');
//         })
//         ->then(function ($data) use ($loop) {
//             // var_dump($data);
//             var_dump(strlen($data));
//             $loop->stop();
//         });

// $obj = new \StdClass();
// $obj->x = 213;
// $obj->qwe = 'qwe';
// $client->set('a', $obj)
//         ->then(function () use ($client) {
//             return $client->get('a');
//         })
//         ->then(function ($data) use ($loop) {
//             // var_dump($data);
//             var_dump($data);
//             $loop->stop();
//         });

// $client->set('a', ['qwe' => 'ads', 123 => 3434])
//         ->then(function () use ($client) {
//             return $client->get('a');
//         })
//         ->then(function ($data) use ($loop) {
//             // var_dump($data);
//             var_dump($data);
//             $loop->stop();
//         });

$client->set('a', ['qwe' => 'ads', 123 => 3434])
        ->then(function () use ($client) {
            return $client->get('a');
        })
        ->then(function ($data) use ($loop) {
            // var_dump($data);
            var_dump($data);
            $loop->stop();
        });

$loop->run();
