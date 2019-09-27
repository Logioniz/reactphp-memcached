<?php
namespace Logioniz\React\Memcached;

use Logioniz\React\Memcached\Exception\UnknownResponseException;

class Response
{
    const LINE_END = "\r\n";

    private $request;
    private $serializer;

    public $message;

    public function __construct(Request $request, SerializerInterface $serializer)
    {
        $this->request = $request;
        $this->serializer = $serializer;
    }

    public static function parseResponse(Request $request, SerializerInterface $serializer, string &$data): ?self
    {
        $response = new self($request, $serializer);
        if ($response->isMultiLineResponse($response->request->command))
            return $response->parseMultiLineResponse($data);
        return $response->parseSingleLineResponse($data);
    }

    protected function parseSingleLineResponse(string &$originData): ?self
    {
        $index = strpos($originData, self::LINE_END);
        if ($index === false) return null;

        $response = substr($originData, 0, $index);
        $originData = substr($originData, $index + strlen(self::LINE_END));

        $this->message = $response;
        return $this;
    }

    protected function parseMultiLineResponse(string &$originData): ?self
    {
        $data = $originData;
        list($responseLength, $responses) = [0, []];

        while (strlen($data) > 0) {
            $index = strpos($data, self::LINE_END);
            if ($index === false) return null;

            $response = substr($data, 0, $index);

            $data = substr($data, $index + strlen(self::LINE_END));
            $responseLength += $index + strlen(self::LINE_END);

            if ($response === 'END') break;

            if ($this->isCommandLikeGet()) {
                if (!preg_match('/VALUE ([^\s]+) ([\d]+) ([\d]+)(?: ([\d]+))?/', $response, $matches))
                    throw new UnknownResponseException("Recieve unknown response for command {$this->request->command}: " . $response);

                list($key, $flags, $bytes) = [$matches[1], (int)$matches[2], (int)$matches[3]];
                $cas = count($matches) > 4 ? (int)$matches[4] : null;

                if ($bytes + strlen(self::LINE_END) > strlen($data)) return null;

                $rawValue = substr($data, 0, $bytes);
                $value = $this->serializer->unserialize($flags, $rawValue);

                if ($this->isWithCAS()) {
                    $responses[$key] = [
                        'cas'   => $cas,
                        'value' => $value
                    ];
                } else {
                    $responses[$key] = $value;
                }

                $data = substr($data, $bytes + strlen(self::LINE_END));
                $responseLength += $bytes + strlen(self::LINE_END);
            } else {
                array_push($responses, $response);
            }
        }

        if ($this->isCommandLikeGet() && $this->request->oneKey) {
            $this->message = count($responses) > 0 ? $responses[array_keys($responses)[0]] : null;
        } else {
            $this->message = $responses;
        }

        $originData = substr($originData, $responseLength);
        return $this;
    }

    protected function isMultiLineResponse(): bool
    {
        return in_array($this->request->command, ['get', 'gets', 'gat', 'gats', 'stats']);
    }

    protected function isCommandLikeGet(): bool
    {
        return in_array($this->request->command, ['get', 'gets', 'gat', 'gats']);
    }

    protected function isWithCAS(): bool
    {
        return in_array($this->request->command, ['gets', 'gats']);
    }
}
