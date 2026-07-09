<?php

declare(strict_types=1);

namespace Zaphyr\HttpClientTests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Zaphyr\HttpClient\Client;
use Zaphyr\HttpClient\Exceptions\HttpClientException;
use Zaphyr\HttpMessage\Factories\ResponseFactory;
use Zaphyr\HttpMessage\Factories\StreamFactory;
use Zaphyr\HttpMessage\Request;

class ClientTest extends TestCase
{
    /**
     * @var ResponseFactory
     */
    protected ResponseFactory $responseFactory;

    /**
     * @var StreamFactory
     */
    protected StreamFactory $streamFactory;

    /**
     * @var Client
     */
    protected Client $client;

    public function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
        $this->streamFactory = new StreamFactory();

        $this->client = new Client($this->responseFactory, $this->streamFactory);
    }

    public function tearDown(): void
    {
        unset($this->responseFactory, $this->streamFactory, $this->client);
    }

    /* -------------------------------------------------
     * SEND REQUEST
     * -------------------------------------------------
     */

    public function testSendRequestWithGetMethod(): void
    {
        $request = new Request(uri: 'https://www.httpbin.org/get');
        $response = $this->client->sendRequest($request);
        $payload = $this->getResponsePayload($response);

        self::assertEquals(200, $response->getStatusCode());
        self::assertStringContainsString('www.httpbin.org', $payload['headers']['Host']);
    }

    public function testSendRequestWithPostMethod(): void
    {
        $stream = (new StreamFactory())->createStream(http_build_query(['foo' => 'bar']));
        $request = new Request('POST', 'https://www.httpbin.org/post', $stream);
        $response = $this->client->sendRequest($request);
        $payload = $this->getResponsePayload($response);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('bar', $payload['form']['foo']);
    }

    public function testSendRequestWithPostMethodThrowsExceptionWhenBodyNotSeekable(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(self::once())->method('isSeekable')->willReturn(false);

        $request = new Request('POST', 'https://www.httpbin.org/post', $stream);

        $this->expectException(HttpClientException::class);

        $this->client->sendRequest($request);
    }

    public function testSendRequestWithPutMethod(): void
    {
        $stream = (new StreamFactory())->createStream(http_build_query(['foo' => 'bar']));
        $request = new Request('PUT', 'https://www.httpbin.org/put', $stream);
        $response = $this->client->sendRequest($request);
        $payload = $this->getResponsePayload($response);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('bar', $payload['form']['foo']);
    }

    public function testSendRequestWithPutMethodThrowsExceptionWhenBodyNotSeekable(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(self::once())->method('isSeekable')->willReturn(false);

        $request = new Request('PUT', 'https://www.httpbin.org/post', $stream);

        $this->expectException(HttpClientException::class);

        $this->client->sendRequest($request);
    }

    public function testSendRequestWithPatchMethod(): void
    {
        $request = new Request('PATCH', 'https://www.httpbin.org/patch');
        $response = $this->client->sendRequest($request);
        $payload = $this->getResponsePayload($response);

        self::assertEquals(200, $response->getStatusCode());
        self::assertStringContainsString('www.httpbin.org', $payload['headers']['Host']);
    }

    public function testSendRequestWithDeleteMethod(): void
    {
        $request = new Request('DELETE', 'https://www.httpbin.org/delete');
        $response = $this->client->sendRequest($request);
        $payload = $this->getResponsePayload($response);

        self::assertEquals(200, $response->getStatusCode());
        self::assertStringContainsString('www.httpbin.org', $payload['headers']['Host']);
    }

    public function testSendRequestWithHeadMethod(): void
    {
        $request = new Request('HEAD', 'https://www.httpbin.org/get');
        $response = $this->client->sendRequest($request);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('', (string)$response->getBody());
    }

    public function testSendRequestWithBasicAuth(): void
    {
        $request = new Request(uri: 'https://www.httpbin.org/basic-auth/john/secret');
        $request = $request->withHeader('Authorization', 'Basic ' . (base64_encode('john:secret')));
        $response = $this->client->sendRequest($request);
        $payload = $this->getResponsePayload($response);

        self::assertEquals(200, $response->getStatusCode());
        self::assertEquals('true', $payload['authenticated']);
    }

    public function testSendRequestThrowsExceptionWhenUriNotResolvable(): void
    {
        $this->expectException(HttpClientException::class);

        $this->client->sendRequest(new Request(uri: 'https://nope.zaphyr.com'));
    }

    public function testSendRequestThrowsExceptionOnInvalidMethod(): void
    {
        $this->expectException(HttpClientException::class);

        $this->client->sendRequest(new Request('INVALID', 'https://www.httpbin.org/get'));
    }

    protected function getResponsePayload(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true);
    }
}
