<?php

namespace NexCargo\Tests\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NexCargo\ApiException;
use NexCargo\Http\ApiTransport;
use PHPUnit\Framework\TestCase;

final class ApiTransportTest extends TestCase
{
  /**
   * @param array<int, mixed> $queue
   */
  private function transport(array $queue, bool $httpErrors = true): ApiTransport
  {
    return new ApiTransport(new GuzzleClient([
      'handler' => HandlerStack::create(new MockHandler($queue)),
      'http_errors' => $httpErrors,
    ]));
  }

  public function testReturnsDecodedPayloadOnSuccess(): void
  {
    $transport = $this->transport([new Response(200, [], '{"success":true,"data":{"a":1}}')]);

    $this->assertSame(['success' => true, 'data' => ['a' => 1]], $transport->requestJson('GET', '/x', [], 'thing'));
  }

  public function testNonSuccessPayloadThrows(): void
  {
    $transport = $this->transport([new Response(200, [], '{"success":false}')]);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The thing response was not successful.');

    $transport->requestJson('GET', '/x', [], 'thing');
  }

  public function testSuccessfulResponseWithoutSuccessKeyIsReturned(): void
  {
    $transport = $this->transport([new Response(200, [], '{"data":{"a":1}}')]);

    $this->assertSame(['data' => ['a' => 1]], $transport->requestJson('GET', '/x', [], 'thing'));
  }

  public function testInvalidJsonOnSuccessThrows(): void
  {
    $transport = $this->transport([new Response(200, [], 'nope')]);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The thing response contains invalid JSON.');

    $transport->requestJson('GET', '/x', [], 'thing');
  }

  public function testNonObjectJsonThrows(): void
  {
    $transport = $this->transport([new Response(200, [], '"text"')]);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The thing response must contain a JSON object.');

    $transport->requestJson('GET', '/x', [], 'thing');
  }

  public function testHttpErrorKeepsStructuredDetailsAndPrevious(): void
  {
    $body = '{"success":false,"request_id":"r1",'
      . '"error":{"code":"RATE_LIMIT_EXCEEDED","message":"Slow","retry_after":9}}';
    $transport = $this->transport([new Response(429, ['Retry-After' => '30'], $body)]);

    try {
      $transport->requestJson('GET', '/x', [], 'thing');
      $this->fail('Expected ApiException.');
    } catch (ApiException $e) {
      $this->assertSame(429, $e->getStatusCode());
      $this->assertSame('RATE_LIMIT_EXCEEDED', $e->getApiCode());
      $this->assertSame('r1', $e->getRequestId());
      $this->assertSame('30', $e->getRetryAfter());
      $this->assertNotNull($e->getPrevious());
    }
  }

  public function testHttpErrorWithoutJsonThrowsGenericMessage(): void
  {
    $transport = $this->transport([new Response(500, [], '')], false);

    try {
      $transport->requestJson('GET', '/x', [], 'thing');
      $this->fail('Expected ApiException.');
    } catch (ApiException $e) {
      $this->assertSame('The thing request failed with HTTP status 500.', $e->getMessage());
      $this->assertSame(500, $e->getStatusCode());
    }
  }

  public function testConnectionErrorIsWrapped(): void
  {
    $transport = $this->transport([new ConnectException('down', new Request('GET', '/x'))]);

    try {
      $transport->requestJson('GET', '/x', [], 'thing');
      $this->fail('Expected ApiException.');
    } catch (ApiException $e) {
      $this->assertSame('down', $e->getMessage());
      $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
    }
  }

  public function testDoesNotRetryRequests(): void
  {
    $mock = new MockHandler([new Response(500, [], '{"success":false}'), new Response(200, [], '{"success":true}')]);
    $transport = new ApiTransport(new GuzzleClient(['handler' => HandlerStack::create($mock)]));

    try {
      $transport->requestJson('POST', '/x', [], 'thing');
      $this->fail('Expected ApiException.');
    } catch (ApiException) {
      $this->assertCount(1, $mock);
    }
  }
}
