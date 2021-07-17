<?php

use GuzzleHttp\Psr7\Response;
use Jsor\HalClient\HttpClient\HttpClientInterface;
use Psr\Http\Message\RequestInterface;

class CRM_OSDI_FixtureHttpClient implements HttpClientInterface
{
    private $homunculus;

    public function __construct()
    {
        $this->homunculus = new \Jsor\HalClient\HttpClient\Guzzle6HttpClient();
    }

    public static function resetHistory()
    {
        $fixtureDir = __DIR__ . '/Fixture/httpResponses/';
        $historyFile = $fixtureDir . 'history';
        if (file_exists($historyFile)) {
            unlink($historyFile);
        }
    }

    public function send(RequestInterface $request)
    {
        $fixtureDir = __DIR__ . '/Fixture/httpResponses/';
        $historyFile = $fixtureDir . 'history';
        $headers = $this->getRelevantHeaders($request);
        $request = $this->withPersistableBody($request);
        $history = $this->getHistory($historyFile);
        $history[] = json_encode(
            [
                'request' => [
                    $request->getRequestTarget(),
                    $request->getMethod(),
                    $headers,
                    $request->getBody()->getContents(),
                ]
            ]
        );
        $requestId = md5(implode('', $history));
        $cacheFile = $fixtureDir . $requestId;
        if (file_exists($cacheFile)) {
            $rawRecord = file($cacheFile, FILE_IGNORE_NEW_LINES);
            $responseArr = json_decode(array_pop($rawRecord), true);
            file_put_contents($historyFile, join("\n", $history));
            return $this->thawResponse($responseArr);
        }

        $rawResponse = $this->homunculus->send($request);
        file_put_contents($historyFile, join("\n", $history));
        $persistableResponse = $this->freezeResponse($rawResponse);
        $recordToWrite = $history;
        array_push($recordToWrite, json_encode($persistableResponse));
        file_put_contents($cacheFile, join($recordToWrite, "\n"));

        return $this->thawResponse($persistableResponse);
    }

    /**
     * @param Response|null $rawResponse
     * @return array
     */
    private function freezeResponse(?Response $rawResponse): array
    {
        return [
            'status' => $rawResponse->getStatusCode(),
            'headers' => $rawResponse->getHeaders(),
            'body' => $rawResponse->getBody()->getContents(),
            'version' => $rawResponse->getProtocolVersion(),
            'reason' => $rawResponse->getReasonPhrase()
        ];
    }

    /**
     * @param $responseArr
     */
    private function thawResponse($responseArr): Response
    {
        return new Response(
            $responseArr['status'],
            $responseArr['headers'],
            $responseArr['body'],
            $responseArr['version'],
            $responseArr['reason']
        );
    }

    // Borrowed from \Jsor\HalClient\HalClient
    private function withPersistableBody(RequestInterface $request)
    {
        $body = $request->getBody()->getContents();
        if (is_array($body)) {
            $body = json_encode($body);

            if (!$request->hasHeader('Content-Type')) {
                $request = $request->withHeader(
                    'Content-Type',
                    'application/json'
                );
            }
        }

        return $request->withBody(GuzzleHttp\Psr7\stream_for($body));
    }

    /**
     * @param RequestInterface $request
     * @return string[][]
     */
    private function getRelevantHeaders(RequestInterface $request): array
    {
        $headers = $request->getHeaders();
        unset($headers['Host'], $headers['User-Agent'], $headers['Accept'], $headers['Content-Type']);
        if (isset($headers['OSDI-API-Token'])) {
            $headers['OSDI-API-Token'] = 'redacted';
        }
        return $headers;
    }

    /**
     * @param string $historyFile
     * @return array|false
     */
    private function getHistory(string $historyFile)
    {
        if (file_exists($historyFile)) {
            $history = file($historyFile, FILE_IGNORE_NEW_LINES);
        }
        if (empty($history)) {
            $history = [];
        }
        return $history;
    }

}

