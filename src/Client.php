<?php

declare(strict_types=1);

namespace Zaphyr\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Zaphyr\HttpClient\Exceptions\HttpClientException;

/**
 * @author merloxx <merloxx@zaphyr.org>
 */
class Client implements ClientInterface
{
    /**
     * @var array<int, mixed>
     */
    private array $options = [
        CURLOPT_HEADER => true,
        CURLINFO_HEADER_OUT => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_FRESH_CONNECT => true,
    ];

    /**
     * @param ResponseFactoryInterface $responseFactory
     * @param StreamFactoryInterface   $streamFactory
     * @param array<int, mixed>        $options
     */
    public function __construct(
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        array $options = []
    ) {
        $this->options = array_replace($this->options, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->resolveMethod($request);
        $this->resolveHeaders($request);
        $this->resolveUri($request);

        $handle = curl_init();
        curl_setopt_array($handle, $this->options);
        /** @var string|false $result */
        $result = curl_exec($handle);
        $info = curl_getinfo($handle);
        curl_close($handle);


        if ($result === false) {
            throw new HttpClientException(curl_error($handle), curl_errno($handle));
        }

        return $this->resolveResponse($info, $result);
    }

    /**
     * @param RequestInterface $request
     *
     * @throws HttpClientException
     * @return void
     */
    private function resolveMethod(RequestInterface $request): void
    {
        unset(
            $this->options[CURLOPT_HTTPGET],
            $this->options[CURLOPT_POST],
            $this->options[CURLOPT_POSTFIELDS],
            $this->options[CURLOPT_CUSTOMREQUEST]
        );

        match ($request->getMethod()) {
            'GET' => $this->options[CURLOPT_HTTPGET] = 1,
            'POST' => $this->resolvePostMethod($request),
            'PUT' => $this->resolvePutMethod($request),
            'PATCH' => $this->options[CURLOPT_CUSTOMREQUEST] = 'PATCH',
            'DELETE' => $this->options[CURLOPT_CUSTOMREQUEST] = 'DELETE',
            'HEAD' => $this->resolveHeadMethod(),
            default => throw new HttpClientException('Invalid request method', 400),
        };
    }

    /**
     * @param RequestInterface $request
     *
     * @throws HttpClientException
     * @return void
     */
    private function resolvePostMethod(RequestInterface $request): void
    {
        if ($request->getBody()->isSeekable() === false) {
            throw new HttpClientException('The request body is not seekable', 400);
        }

        $this->options[CURLOPT_POST] = 1;
        $this->options[CURLOPT_POSTFIELDS] = (string)$request->getBody();
    }

    /**
     * @param RequestInterface $request
     *
     * @throws HttpClientException
     * @return void
     */
    private function resolvePutMethod(RequestInterface $request): void
    {
        if ($request->getBody()->isSeekable() === false) {
            throw new HttpClientException('The request body is not seekable', 400);
        }

        $this->options[CURLOPT_POST] = 1;
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        $this->options[CURLOPT_POSTFIELDS] = (string)$request->getBody();
    }

    /**
     * @return void
     */
    private function resolveHeadMethod(): void
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
        $this->options[CURLOPT_NOBODY] = true;
    }

    /**
     * @param RequestInterface $request
     *
     * @return void
     */
    private function resolveHeaders(RequestInterface $request): void
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name . ': ' . implode(', ', $values);
        }

        $this->options[CURLOPT_HTTPHEADER] = $headers;
    }

    /**
     * @param RequestInterface $request
     *
     * @return void
     */
    private function resolveUri(RequestInterface $request): void
    {
        $uri = $request->getUri();

        if ($uri->getUserInfo() !== '') {
            $this->options[CURLOPT_USERPWD] = $uri->getUserInfo();
        }

        $port = $uri->getPort() ?: 80;
        $port = $uri->getScheme() === 'https' ? 443 : $port;

        $this->options[CURLOPT_PORT] = $port;
        $this->options[CURLOPT_URL] = $uri->__toString();
    }

    /**
     * @param array<string, mixed> $info
     * @param string               $result
     *
     * @return ResponseInterface
     */
    private function resolveResponse(array $info, string $result): ResponseInterface
    {
        $bodySize = intval($info['size_download']);
        $body = $bodySize === 0 ? '' : substr($result, $bodySize * -1);
        $stream = $this->streamFactory->createStream($body);

        $response = $this->responseFactory->createResponse($info['http_code'])->withBody($stream);

        $headersString = substr($result, 0, intval($info['header_size']));
        $headers = $this->resolveHeadersString($headersString);

        foreach ($headers as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }

    /**
     * @param string $headersString
     *
     * @return array<string, string[]>
     */
    private function resolveHeadersString(string $headersString): array
    {
        $headers = [];

        $newLine = strpos($headersString, "\r\n") ? "\r\n" : "\n";
        $headerParts = explode($newLine, $headersString);

        foreach ($headerParts as $header) {
            $header = trim($header);

            if ($header === '' || !str_contains($header, ':')) {
                continue;
            }

            [$key, $values] = explode(': ', $header, 2);
            // @todo prüfen ob nach "," wirklich nochmal "exploded" werden muss
            $headers[$key] = explode(', ', $values);
        }

        return $headers;
    }
}
