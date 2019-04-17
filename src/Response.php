<?php
namespace Logioniz\React\Memcached;

class Response
{
    private $request;
    private $serializer;

    public $response;

    public function __construct(Request $request, SerializerInterface $serializer)
    {
        $this->request = $request;
        $this->serializer = $serializer;
    }

    public function parseResponse(&$originData)
    {
        $data = $originData;

        if ($this->request->command === 'set') {
            $index = strpos($data, "\r\n");
            if ($index === false)
                return null;

            $response = substr($data, 0, $index);
            if (!in_array($response, ['STORED', 'NOT_STORED']))
                throw new \Exception("Recieve unknown response for command set", 1);

            $this->response = $response;

            $originData = substr($originData, $index + 2);

            return true;
        } elseif (in_array($this->request->command, ['get', 'gets'])) {
            $responses = [];
            $responseLength = 0;
            while (strlen($data) > 0) {
                $index = strpos($data, "\r\n");
                if ($index === false)
                    return null;

                $response = substr($data, 0, $index);

                if (strpos($response, "END") === 0) {
                    $responseLength += $index + 2;
                    break;
                }

                if (!preg_match('/VALUE ([^\s]+) ([\d]+) ([\d]+)(?: ([\d]+))?/', $response, $matches))
                    throw new \Exception("Recieve unknown response for command get/gets", 1);

                $key   = $matches[1];
                $flags = (int)$matches[2];
                $bytes = (int)$matches[3];
                $cas   = null;

                if (count($matches) > 4)
                    $cas = (int)$matches[4];

                $itemLen = $index + 2 + $bytes + 2;

                if ($itemLen > strlen($data))
                    return false;

                $rawValue = substr($data, $index + 2, $bytes);
                $value = $this->serializer->unserialize($flags, $rawValue);

                if ($this->request->command === 'get') {
                    $responses[$key] = $value;
                } else {
                    $responses[$key] = [
                        'cas'   => $cas,
                        'value' => $value
                    ];
                }

                $responseLength += $itemLen;
                $data = substr($data, $itemLen);
            }

            if ($this->request->info->oneKey) {
                $this->response = count($responses) > 0 ? $responses[array_keys($responses)[0]] : null;
            } else {
                $this->response = $responses;
            }

            $originData = substr($originData, $responseLength);

            return true;
        }
    }
}
