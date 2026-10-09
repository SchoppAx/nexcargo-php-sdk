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
    $httpClientProperty = new \ReflectionProperty(Client::class, 'httpClient');
    $httpClient = $httpClientProperty->getValue($client);

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

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('The shipment tracking request failed with HTTP status 500.');

    $client->getShipmentTracking('abc123');
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
    $mock = new MockHandler([new TransferException('Connection failed', new GuzzleRequest('GET', '/'))]);
    $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
    $client = new Client('test-key', 'https://api.example.com', $guzzle);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Connection failed');

    $client->getShipmentLabelContent('abc123');
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
