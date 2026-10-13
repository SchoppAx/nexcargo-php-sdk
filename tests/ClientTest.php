<?php

namespace NexCargo\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use NexCargo\ApiException;
use NexCargo\Client;
use NexCargo\Http\ApiTransport;
use NexCargo\Tests\Fixtures;
use NexCargo\Models\Address;
use NexCargo\Models\Dimensions;
use NexCargo\Models\Parcel;
use NexCargo\Models\QuoteRequest;
use NexCargo\Models\ShipmentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @phpstan-type HistoryEntry array{
 *   request: RequestInterface,
 *   response: ResponseInterface|null,
 *   error: mixed,
 *   options: array<mixed>
 * }
 * @phpstan-type History array<array-key, HistoryEntry>|\ArrayAccess<int, HistoryEntry>
 */
class ClientTest extends TestCase
{
  public function testConstructorConfiguresBaseUrlAndDefaultHeaders(): void
  {
    $client = new Client('test-key', 'https://api.example.com/base');
    $transport = (new \ReflectionProperty(Client::class, 'transport'))->getValue($client);
    $httpClient = (new \ReflectionProperty(ApiTransport::class, 'httpClient'))->getValue($transport);

    self::assertInstanceOf(GuzzleClient::class, $httpClient);
    self::assertSame('https://api.example.com/base/', (string) $httpClient->getConfig('base_uri'));

    $headers = $httpClient->getConfig('headers');
    self::assertIsArray($headers);
    self::assertSame('Bearer test-key', $headers['Authorization'] ?? null);
    self::assertSame('application/json', $headers['Content-Type'] ?? null);
    self::assertSame('application/json', $headers['Accept'] ?? null);
  }

  /**
   * @param History $history
   */
  private function getHistoryRequest(array|\ArrayAccess $history): RequestInterface
  {
    if (!isset($history[0])) {
      self::fail('No HTTP request was recorded.');
    }

    return $history[0]['request'];
  }

  /**
   * @return iterable<string, array{0: ShipmentRequest, 1: array<string, mixed>}>
   */
  public static function shipmentRequestProvider(): iterable
  {
    $shipmentFields = [
      'carrier' => 'auto',
      'pickup_date' => '2026-01-15',
      'pickup_time_from' => '09:00',
      'pickup_time_to' => '12:00',
      'delivery_date' => '2026-01-16',
      'delivery_time_from' => '08:00',
      'delivery_time_to' => '18:00',
    ];
    $pickupData = [
      'name' => 'Musterfirma GmbH',
      'street' => 'Musterstraße',
      'house_number' => '123',
      'zipcode' => '50667',
      'city' => 'Köln',
      'country' => 'DE',
    ];
    $deliveryData = [
      'name' => 'Empfänger GmbH',
      'street' => 'Beispielweg',
      'house_number' => '456',
      'zipcode' => '10115',
      'city' => 'Berlin',
      'country' => 'DE',
    ];
    $packageData = ['weight' => 15.5, 'length' => 60, 'width' => 40, 'height' => 30];

    yield 'raw array payload' => [
      new ShipmentRequest($shipmentFields + [
        'pickup' => $pickupData,
        'delivery' => $deliveryData,
        'packages' => [$packageData],
      ]),
      $shipmentFields + [
        'pickup' => $pickupData,
        'delivery' => $deliveryData,
        'packages' => [$packageData],
      ],
    ];

    yield 'model object payload' => [
      new ShipmentRequest($shipmentFields + [
        'pickup' => new Address($pickupData),
        'delivery' => new Address($deliveryData),
        'packages' => [new Parcel([
          'weight' => $packageData['weight'],
          'dimensions' => new Dimensions($packageData),
        ])],
      ]),
      $shipmentFields + [
        'pickup' => $pickupData,
        'delivery' => $deliveryData,
        'packages' => [[
          'weight' => $packageData['weight'],
          'length' => $packageData['length'],
          'width' => $packageData['width'],
          'height' => $packageData['height'],
        ]],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $expectedBody
   */
  #[DataProvider('shipmentRequestProvider')]
  public function testCreateShipmentSerializesPayload(ShipmentRequest $shipmentRequest, array $expectedBody): void
  {
    /** @var History $history */
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], '{"success":true,"data":{"id":"abc123"}}'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));
    $guzzle = new GuzzleClient(['handler' => $handlerStack]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $response = $client->createShipment($shipmentRequest);
    $request = $this->getHistoryRequest($history);

    $this->assertSame(['success' => true, 'data' => ['id' => 'abc123']], $response);
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('/api/v1/shipments', (string) $request->getUri()->getPath());
    $this->assertSame($expectedBody, json_decode((string) $request->getBody(), true));
  }

  public function testListShipmentsWithoutFilters(): void
  {
    /** @var History $history */
    $history = [];
    $responsePayload = ['success' => true, 'data' => [], 'meta' => ['page' => 1], 'extra' => 'kept'];
    $handlerStack = HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode($responsePayload, JSON_THROW_ON_ERROR)),
    ]));
    $handlerStack->push(Middleware::history($history));
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient(['handler' => $handlerStack]));

    $result = $client->listShipments();
    $request = $this->getHistoryRequest($history);

    $this->assertSame($responsePayload, $result);
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('/api/v1/shipments', (string) $request->getUri()->getPath());
    $this->assertSame('', $request->getUri()->getQuery());
  }

  public function testListShipmentsSendsAllAllowedFiltersAndPagination(): void
  {
    /** @var History $history */
    $history = [];
    $handlerStack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"success":true,"data":[]}'),
    ]));
    $handlerStack->push(Middleware::history($history));
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient(['handler' => $handlerStack]));
    $filters = [
      'status' => 'created',
      'carrier' => 'fast',
      'from_date' => '2026-01-01',
      'to_date' => '2026-01-31',
      'reference' => 'ORDER-1',
      'per_page' => 25,
      'page' => 2,
    ];

    $client->listShipments($filters);
    $request = $this->getHistoryRequest($history);
    parse_str($request->getUri()->getQuery(), $query);

    $this->assertSame([
      'status' => 'created',
      'carrier' => 'fast',
      'from_date' => '2026-01-01',
      'to_date' => '2026-01-31',
      'reference' => 'ORDER-1',
      'per_page' => '25',
      'page' => '2',
    ], $query);
  }

  public function testListShipmentsOmitsNullAndUnsupportedFiltersButKeepsZero(): void
  {
    /** @var History $history */
    $history = [];
    $handlerStack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"success":true,"data":[]}'),
    ]));
    $handlerStack->push(Middleware::history($history));
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient(['handler' => $handlerStack]));

    $client->listShipments([
      'status' => null,
      'carrier' => 'fast',
      'reference' => null,
      'per_page' => 0,
      'page' => 0,
      'ignored' => 'not forwarded',
    ]);
    $request = $this->getHistoryRequest($history);
    parse_str($request->getUri()->getQuery(), $query);

    $this->assertSame(['carrier' => 'fast', 'per_page' => '0', 'page' => '0'], $query);
  }

  public function testGetShipmentAcceptsNumericIdentifierAndPreservesResponse(): void
  {
    /** @var History $history */
    $history = [];
    $responsePayload = [
      'success' => true,
      'shipment' => ['id' => 123],
      'unknown_field' => ['kept' => true],
      'data' => ['adjustments' => [['code' => 'PALLET_ADJUSTED']]],
      'meta' => ['pallet' => ['product' => 'PA', 'validated' => true]],
    ];
    $handlerStack = HandlerStack::create(new MockHandler([
      new Response(200, [], json_encode($responsePayload, JSON_THROW_ON_ERROR)),
    ]));
    $handlerStack->push(Middleware::history($history));
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient(['handler' => $handlerStack]));

    $result = $client->getShipment(123);
    $request = $this->getHistoryRequest($history);

    $this->assertSame($responsePayload, $result);
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('/api/v1/shipments/123', (string) $request->getUri()->getPath());
  }

  public function testGetShipmentAcceptsTrackingNumberIdentifier(): void
  {
    /** @var History $history */
    $history = [];
    $handlerStack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"success":true,"data":{"tracking_number":"49111091097"}}'),
    ]));
    $handlerStack->push(Middleware::history($history));
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient(['handler' => $handlerStack]));

    $client->getShipment('49111091097');
    $request = $this->getHistoryRequest($history);

    $this->assertSame('/api/v1/shipments/49111091097', (string) $request->getUri()->getPath());
  }

  public function testGetShipmentEncodesCustomerReferenceAsPathSegment(): void
  {
    /** @var History $history */
    $history = [];
    $handlerStack = HandlerStack::create(new MockHandler([
      new Response(200, [], '{"success":true,"data":[]}'),
    ]));
    $handlerStack->push(Middleware::history($history));
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient(['handler' => $handlerStack]));
    $identifier = 'order/one #?';

    $client->getShipment($identifier);
    $request = $this->getHistoryRequest($history);

    $this->assertSame('/api/v1/shipments/order%2Fone%20%23%3F', (string) $request->getUri()->getPath());
  }

  public function testGetShipmentLabelContentUsesLabelEndpoint(): void
  {
    /** @var History $history */
    $history = [];
    $labelResponse = json_encode([
      'success' => true,
      'data' => [
        'label' => [
          'format' => 'pdf',
          'encoding' => 'base64',
          'content' => base64_encode('%PDF-1.4'),
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    $mock = new MockHandler([
      new Response(200, [], $labelResponse),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));
    $guzzle = new GuzzleClient(['handler' => $handlerStack]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $pdfContents = $client->getShipmentLabelContent('abc123');
    $request = $this->getHistoryRequest($history);

    $this->assertSame('%PDF-1.4', $pdfContents);
    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('/api/v1/shipments/abc123/label', (string) $request->getUri()->getPath());
  }

  public function testGetShipmentLabelContentDecodesPdfContent(): void
  {
    $pdfContents = '%PDF-1.4';
    $labelResponse = json_encode([
      'success' => true,
      'data' => [
        'label' => [
          'format' => 'pdf',
          'encoding' => 'base64',
          'content' => base64_encode($pdfContents),
        ],
      ],
    ], JSON_THROW_ON_ERROR);
    $mock = new MockHandler([
      new Response(200, [], $labelResponse),
    ]);

    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->assertSame($pdfContents, $client->getShipmentLabelContent('abc123'));
  }

  public function testGetShipmentLabelContentRejectsInvalidJson(): void
  {
    $mock = new MockHandler([
      new Response(200, [], '{invalid-json'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment label response contains invalid JSON.');

    $client->getShipmentLabelContent('abc123');
  }

  public function testGetShipmentLabelContentRejectsMissingLabelData(): void
  {
    $mock = new MockHandler([
      new Response(200, [], '{"success":true,"data":{}}'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment label response is missing the label data.');

    $client->getShipmentLabelContent('abc123');
  }

  public function testGetShipmentLabelContentRejectsUnsupportedFormat(): void
  {
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'success' => true,
        'data' => ['label' => ['format' => 'png', 'encoding' => 'base64', 'content' => 'abc']],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment label response has an unsupported format or encoding.');

    $client->getShipmentLabelContent('abc123');
  }

  public function testGetShipmentLabelContentRejectsMissingContent(): void
  {
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'success' => true,
        'data' => ['label' => ['format' => 'pdf', 'encoding' => 'base64']],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment label response is missing base64 content.');

    $client->getShipmentLabelContent('abc123');
  }

  public function testGetShipmentLabelContentRejectsInvalidBase64(): void
  {
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'success' => true,
        'data' => ['label' => ['format' => 'pdf', 'encoding' => 'base64', 'content' => '!!!not-base64!!!']],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment label content is not valid base64.');

    $client->getShipmentLabelContent('abc123');
  }

  public function testGetShipmentLabelContentRejectsNullLabel(): void
  {
    $mock = new MockHandler([
      new Response(200, [], json_encode(['success' => true, 'data' => ['label' => null]], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment label response is missing the label data.');

    $client->getShipmentLabelContent('abc123');
  }

  public function testGetShipmentLabelContentRejectsEmptyContent(): void
  {
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'success' => true,
        'data' => ['label' => ['format' => 'pdf', 'encoding' => 'base64', 'content' => '']],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment label response is missing base64 content.');

    $client->getShipmentLabelContent('abc123');
  }

  public function testGetShipmentLabelContentKeepsApiErrors(): void
  {
    $mock = new MockHandler([
      new Response(422, [], json_encode([
        'success' => false,
        'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid.'],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    try {
      $client->getShipmentLabelContent('abc123');
      $this->fail('Expected ApiException.');
    } catch (ApiException $exception) {
      $this->assertSame('VALIDATION_ERROR', $exception->getApiCode());
      $this->assertSame(422, $exception->getStatusCode());
    }
  }

  /**
   * @return array<string, array{0: int, 1: string}>
   */
  public static function apiErrorResponsesProvider(): array
  {
    return [
      '400 validation error' => [400, 'VALIDATION_ERROR'],
      '409 cancellation not supported' => [409, 'CANCEL_NOT_SUPPORTED'],
      '422 pallet rule violation' => [422, 'PALLET_RULE_VIOLATION'],
      '429 rate limit exceeded' => [429, 'RATE_LIMIT_EXCEEDED'],
    ];
  }

  #[DataProvider('apiErrorResponsesProvider')]
  public function testApiErrorsExposeStatusCodeAndErrorDetails(int $statusCode, string $apiCode): void
  {
    $details = ['field' => ['Rejected value']];
    $mock = new MockHandler([
      new Response($statusCode, [], json_encode([
        'success' => false,
        'error' => [
          'code' => $apiCode,
          'message' => 'Request rejected',
          'details' => $details,
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    try {
      $client->getShipmentTracking('abc123');
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      self::assertSame($statusCode, $exception->getStatusCode());
      self::assertSame($statusCode, $exception->getCode());
      self::assertSame($apiCode, $exception->getApiCode());
      self::assertSame('Request rejected', $exception->getMessage());
      self::assertSame($details, $exception->getDetails());
    }
  }

  public function testApiExceptionKeepsExistingConstructorArgumentsCompatible(): void
  {
    $previous = new \RuntimeException('previous');
    $exception = new ApiException('legacy message', 422, $previous, 'LEGACY_ERROR', ['field' => 'invalid']);

    self::assertSame('legacy message', $exception->getMessage());
    self::assertSame(422, $exception->getCode());
    self::assertSame($previous, $exception->getPrevious());
    self::assertSame('LEGACY_ERROR', $exception->getApiCode());
    self::assertSame(['field' => 'invalid'], $exception->getDetails());
    self::assertNull($exception->getStatusCode());
    self::assertSame([], $exception->getResponseHeaders());
  }

  public function testApiExceptionExposesRequestMetadataRetryHeaderAndRateLimitHeaders(): void
  {
    $responsePayload = [
      'success' => false,
      'request_id' => 'req-123',
      'timestamp' => '2026-10-09T10:00:00Z',
      'error' => [
        'code' => 'RATE_LIMIT_EXCEEDED',
        'message' => 'Slow down',
        'retry_after' => 12,
      ],
    ];
    $mock = new MockHandler([
      new Response(429, [
        'Retry-After' => '30',
        'X-RateLimit-Limit' => '100',
        'X-RateLimit-Remaining' => '0',
        'X-RateLimit-Reset' => '1791540000',
      ], json_encode($responsePayload, JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    try {
      $client->listShipments();
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      self::assertSame('req-123', $exception->getRequestId());
      self::assertSame('2026-10-09T10:00:00Z', $exception->getTimestamp());
      self::assertSame('30', $exception->getRetryAfter());
      self::assertSame($responsePayload, $exception->getResponseBody());
      self::assertSame(json_encode($responsePayload, JSON_THROW_ON_ERROR), $exception->getRawResponseBody());
      self::assertSame('30', $exception->getResponseHeaders()['Retry-After'][0]);
      self::assertSame([
        'X-RateLimit-Limit' => ['100'],
        'X-RateLimit-Remaining' => ['0'],
        'X-RateLimit-Reset' => ['1791540000'],
      ], $exception->getRateLimitHeaders());
    }
  }

  public function testApiExceptionUsesRetryAfterFromErrorBodyWhenHeaderIsMissing(): void
  {
    $mock = new MockHandler([
      new Response(429, [], '{"success":false,"error":{"code":"RATE_LIMIT_EXCEEDED","retry_after":7.5}}'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    try {
      $client->listShipments();
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      self::assertSame(7.5, $exception->getRetryAfter());
    }
  }

  public function testHttpErrorWithJsonRetainsResponseAndPreviousRequestException(): void
  {
    $responsePayload = [
      'success' => false,
      'request_id' => 'server-request',
      'error' => [
        'code' => 'SERVER_ERROR',
        'message' => 'Unexpected server error',
        'details' => ['trace' => 'available'],
      ],
    ];
    $mock = new MockHandler([
      new Response(500, ['X-RateLimit-Remaining' => '4'], json_encode($responsePayload, JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    try {
      $client->listShipments();
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      self::assertSame(500, $exception->getStatusCode());
      self::assertSame('SERVER_ERROR', $exception->getApiCode());
      self::assertSame('server-request', $exception->getRequestId());
      self::assertSame($responsePayload, $exception->getResponseBody());
      self::assertSame('4', $exception->getRateLimitHeaders()['X-RateLimit-Remaining'][0]);
      self::assertInstanceOf(\GuzzleHttp\Exception\ResponseException::class, $exception->getPrevious());
    }
  }

  public function testEmptyHttpErrorBodyDoesNotCauseSecondaryDecodeFailure(): void
  {
    $mock = new MockHandler([
      new Response(500, [], ''),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    try {
      $client->listShipments();
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      self::assertSame(500, $exception->getStatusCode());
      self::assertSame('', $exception->getRawResponseBody());
      self::assertNull($exception->getResponseBody());
      self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
    }
  }

  public function testApiErrorIncludesCodeAndDetails(): void
  {
    $mock = new MockHandler([
      new Response(422, [], json_encode([
        'success' => false,
        'error' => [
          'code' => 'VALIDATION_ERROR',
          'message' => 'The shipment data is invalid',
          'details' => [
            'pickup_date' => [
              'The pickup date field must be a date after or equal to today.',
            ],
          ],
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    try {
      $client->getShipmentTracking('abc123');
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      $this->assertSame('The shipment data is invalid', $exception->getMessage());
      $this->assertSame('VALIDATION_ERROR', $exception->getApiCode());
      $this->assertSame([
        'pickup_date' => [
          'The pickup date field must be a date after or equal to today.',
        ],
      ], $exception->getDetails());
    }
  }

  public function testHttpErrorWithNonJsonBodyUsesStatusMessage(): void
  {
    $mock = new MockHandler([
      new Response(500, [], 'Internal Server Error'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    try {
      $client->getShipmentTracking('abc123');
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      self::assertSame('The shipment tracking request failed with HTTP status 500.', $exception->getMessage());
      self::assertSame(500, $exception->getStatusCode());
      self::assertSame('Internal Server Error', $exception->getRawResponseBody());
      self::assertNull($exception->getResponseBody());
      self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
    }
  }

  public function testHttpErrorWithJsonBodyButNoErrorKeyUsesStatusMessage(): void
  {
    $mock = new MockHandler([
      new Response(500, [], '{"success":false}'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment tracking request failed with HTTP status 500.');

    $client->getShipmentTracking('abc123');
  }

  public function testErrorWithoutMessageUsesFallbackMessage(): void
  {
    $mock = new MockHandler([
      new Response(422, [], '{"success":false,"error":{"code":"VALIDATION_ERROR"}}'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
      'http_errors' => false,
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment tracking request failed.');

    $client->getShipmentTracking('abc123');
  }

  public function testUnsuccessfulResponseWithoutErrorKeyUsesFallbackMessage(): void
  {
    $mock = new MockHandler([
      new Response(200, [], '{"success":false}'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment tracking response was not successful.');

    $client->getShipmentTracking('abc123');
  }

  public function testSuccessfulResponseThatIsNotAJsonObjectIsRejected(): void
  {
    $mock = new MockHandler([
      new Response(200, [], '"just a string"'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment tracking response must contain a JSON object.');

    $client->getShipmentTracking('abc123');
  }

  public function testGetShipmentTrackingRejectsMissingTrackingData(): void
  {
    $mock = new MockHandler([
      new Response(200, [], '{"success":true}'),
    ]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment tracking response is missing tracking data.');

    $client->getShipmentTracking('abc123');
  }

  public function testGetShipmentTrackingUsesTrackingEndpoint(): void
  {
    /** @var History $history */
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'success' => true,
        'data' => [
          'tracking_number' => '49111091097',
          'status' => 'CANCELLED',
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));
    $guzzle = new GuzzleClient(['handler' => $handlerStack]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $response = $client->getShipmentTracking('abc123');
    $request = $this->getHistoryRequest($history);

    $this->assertSame('GET', $request->getMethod());
    $this->assertSame('49111091097', $response['data']['tracking_number']);
    $this->assertSame('/api/v1/shipments/abc123/tracking', (string) $request->getUri()->getPath());
  }

  public function testCancelShipmentUsesDeleteEndpoint(): void
  {
    /** @var History $history */
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], json_encode([
        'success' => true,
        'data' => [
          'message' => 'Shipment cancelled successfully',
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));
    $guzzle = new GuzzleClient(['handler' => $handlerStack]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $cancelled = $client->cancelShipment('abc123');
    $request = $this->getHistoryRequest($history);

    $this->assertSame('Shipment cancelled successfully', $cancelled['data']['message']);
    $this->assertSame('DELETE', $request->getMethod());
    $this->assertSame('/api/v1/shipments/abc123', (string) $request->getUri()->getPath());
  }

  public function testShipmentSubPathsEncodeIdentifiersAndAcceptIntegers(): void
  {
    $paths = [];
    $label = ['success' => true, 'data' => ['label' => [
      'format' => 'pdf', 'encoding' => 'base64', 'content' => base64_encode('pdf'),
    ]]];
    $tracking = ['success' => true, 'data' => ['status' => 'IN_DELIVERY']];
    $mock = new MockHandler([
      new Response(200, [], json_encode($label, JSON_THROW_ON_ERROR)),
      new Response(200, [], json_encode($tracking, JSON_THROW_ON_ERROR)),
      new Response(200, [], json_encode(['success' => true], JSON_THROW_ON_ERROR)),
    ]);
    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::mapRequest(static function (RequestInterface $request) use (&$paths) {
      $paths[] = $request->getUri()->getPath();

      return $request;
    }));
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient(['handler' => $handlerStack]));

    $client->getShipmentLabelContent('a/b #1');
    $client->getShipmentTracking(123);
    $client->cancelShipment('x?y');

    $this->assertSame([
      '/api/v1/shipments/a%2Fb%20%231/label',
      '/api/v1/shipments/123/tracking',
      '/api/v1/shipments/x%3Fy',
    ], $paths);
  }


  /**
   * @return iterable<string, array{0: QuoteRequest, 1: array<string, mixed>}>
   */
  public static function quoteRequestProvider(): iterable
  {
    $quoteFields = [
      'carrier' => 'auto',
      'pickup_date' => '2026-01-15',
      'pickup_time_from' => '09:00',
      'pickup_time_to' => '12:00',
      'delivery_date' => '2026-01-16',
      'delivery_time_from' => '08:00',
      'delivery_time_to' => '18:00',
    ];
    $pickupData = [
      'name' => 'Musterfirma GmbH',
      'street' => 'Musterstraße',
      'house_number' => '123',
      'zipcode' => '50667',
      'city' => 'Köln',
      'country' => 'DE',
    ];
    $deliveryData = [
      'name' => 'Empfänger GmbH',
      'street' => 'Beispielweg',
      'house_number' => '456',
      'zipcode' => '10115',
      'city' => 'Berlin',
      'country' => 'DE',
    ];
    $packageData = ['weight' => 15.5, 'length' => 60, 'width' => 40, 'height' => 30];

    yield 'raw array payload' => [
      new QuoteRequest($quoteFields + [
        'pickup' => $pickupData,
        'delivery' => $deliveryData,
        'packages' => [$packageData],
      ]),
      $quoteFields + [
        'pickup' => $pickupData,
        'delivery' => $deliveryData,
        'packages' => [$packageData],
      ],
    ];

    yield 'model object payload' => [
      new QuoteRequest($quoteFields + [
        'pickup' => new Address($pickupData),
        'delivery' => new Address($deliveryData),
        'packages' => [new Parcel([
          'weight' => $packageData['weight'],
          'dimensions' => new Dimensions($packageData),
        ])],
      ]),
      $quoteFields + [
        'pickup' => $pickupData,
        'delivery' => $deliveryData,
        'packages' => [[
          'weight' => $packageData['weight'],
          'length' => $packageData['length'],
          'width' => $packageData['width'],
          'height' => $packageData['height'],
        ]],
      ],
    ];
  }

  /**
   * @param array<string, mixed> $expectedBody
   */
  #[DataProvider('quoteRequestProvider')]
  public function testQuoteShipmentSerializesPayload(QuoteRequest $quoteRequest, array $expectedBody): void
  {
    /** @var History $history */
    $history = [];
    $mock = new MockHandler([
      new Response(200, [], '{"success":true,"data":{"amount":42.5}}'),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $handlerStack->push(Middleware::history($history));
    $guzzle = new GuzzleClient(['handler' => $handlerStack]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $response = $client->quoteShipment($quoteRequest);
    $request = $this->getHistoryRequest($history);

    $this->assertSame(['success' => true, 'data' => ['amount' => 42.5]], $response);
    $this->assertSame('POST', $request->getMethod());
    $this->assertSame('/api/v1/shipments/quote', (string) $request->getUri()->getPath());
    $this->assertSame($expectedBody, json_decode((string) $request->getBody(), true));
  }

  public function testRequestWrapsGuzzleExceptionInApiException(): void
  {
    $transferException = new TransferException('Connection failed', new GuzzleRequest('GET', '/'));
    $mock = new MockHandler([$transferException]);
    $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    try {
      $client->getShipmentLabelContent('abc123');
      self::fail('Expected an ApiException.');
    } catch (ApiException $exception) {
      self::assertSame('Connection failed', $exception->getMessage());
      self::assertSame($transferException, $exception->getPrevious());
      self::assertNull($exception->getStatusCode());
    }
  }

  public function testCreateShipmentAcceptsRawArray(): void
  {
    $mock = new MockHandler([new Response(200, [], '{"success":true,"data":{"id":"xyz789"}}')]);
    $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $response = $client->createShipment(Fixtures::shipmentData());

    $this->assertSame(['success' => true, 'data' => ['id' => 'xyz789']], $response);
  }

  public function testCreateShipmentRejectsMissingShipmentData(): void
  {
    $mock = new MockHandler([new Response(200, [], '{"success":true}')]);
    $client = new Client('test-key', 'https://api.example.com', new GuzzleClient([
      'handler' => HandlerStack::create($mock),
    ]));

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The create shipment response is missing shipment data.');

    $client->createShipment(Fixtures::shipmentData());
  }

  public function testQuoteShipmentAcceptsRawArray(): void
  {
    $mock = new MockHandler([new Response(200, [], '{"success":true,"data":{"amount":9.99}}')]);
    $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $response = $client->quoteShipment(Fixtures::quoteData());

    $this->assertSame(['success' => true, 'data' => ['amount' => 9.99]], $response);
  }

  public function testCreateShipmentRequiresRequiredFields(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('ShipmentRequest requires carrier.');

    new ShipmentRequest([]);
  }

  public function testQuoteShipmentRequiresRequiredFields(): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('QuoteRequest requires pickup_date.');

    new QuoteRequest([]);
  }
}
