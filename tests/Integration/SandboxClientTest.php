<?php

declare(strict_types=1);

namespace NexCargo\Tests\Integration;

use DateTimeImmutable;
use NexCargo\Client;
use NexCargo\Tests\Fixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

#[Group('integration')]
#[Group('sandbox')]
final class SandboxClientTest extends TestCase
{
  private Client $client;

  private string $reference;

  protected function setUp(): void
  {
    parent::setUp();

    $this->client = $this->createSandboxClient();

    $this->reference = $this->createReference();
  }

  /**
   * Test the complete NexCargo Sandbox shipment lifecycle.
   *
   * The test deliberately uses one method because the individual operations
   * depend on the shipment created by the previous operation.
   *
   * The shipment cancellation is attempted in the finally block, including
   * when an assertion or API request fails.
   *
   * @throws Throwable
   */
  public function testCompleteSandboxShipmentLifecycle(): void
  {
    $trackingNumber = null;
    $testFailure = null;
    $cancelled = false;

    try {
      $this->requestAndAssertQuote();

      $trackingNumber = $this->createAndAssertShipment();

      $this->assertShipmentCanBeRetrieved($trackingNumber);
      $this->assertShipmentCanBeRetrievedByReference();
      $this->assertShipmentAppearsInList($trackingNumber);
      $this->assertLabelCanBeDownloaded($trackingNumber);
      $this->assertTrackingCanBeRetrieved($trackingNumber);

      $this->cancelAndAssertShipment($trackingNumber);

      $cancelled = true;
    } catch (Throwable $exception) {
      $testFailure = $exception;
    } finally {
      if ($trackingNumber !== null && !$cancelled) {
        $this->cancelSandboxShipment(
          $trackingNumber,
          $testFailure
        );
      }
    }

    if ($testFailure !== null) {
      throw $testFailure;
    }
  }

  private function createSandboxClient(): Client
  {
    $apiKey = $this->requiredEnvironmentVariable('NEXCARGO_SANDBOX_API_KEY');
    $baseUri = $this->requiredEnvironmentVariable('NEXCARGO_BASE_URI');

    $allowWrite = filter_var(
      getenv('NEXCARGO_ALLOW_WRITE') ?: 'false',
      FILTER_VALIDATE_BOOL
    );

    if (!$allowWrite) {
      self::markTestSkipped('Set NEXCARGO_ALLOW_WRITE=true to run the Sandbox lifecycle test.');
    }

    return new Client($apiKey, rtrim($baseUri, '/'));
  }

  private function requiredEnvironmentVariable(string $name): string
  {
    $value = getenv($name);

    if (!is_string($value) || trim($value) === '') {
      self::markTestSkipped(sprintf('%s is not configured.', $name));
    }

    return trim($value);
  }

  private function createReference(): string
  {
    return sprintf(
      'SDK-REGRESSION-%s-%s',
      (new DateTimeImmutable())->format('Ymd-His'),
      bin2hex(random_bytes(3))
    );
  }

  private function requestAndAssertQuote(): void
  {
    $quotePayload = $this->createPayload();
    // Remove the carrier field to test the sandbox quote endpoint without specifying a carrier.
    unset($quotePayload['carrier']);
    $response = $this->client->quoteShipment($quotePayload);

    $this->assertSuccessfulResponse($response, 'Quote response');
    $this->assertSandboxResponse('quotes', $response);
    $this->extractResponseData('quotes', $response);
  }

  private function createAndAssertShipment(): string
  {
    $shipmentPayload = $this->createPayload();
    $response = $this->client->createShipment($shipmentPayload);

    $this->assertSuccessfulResponse($response, 'Create shipment response');
    $this->assertSandboxResponse('shipment', $response);

    $dataType = $this->extractResponseData('shipment', $response);
    return $this->extractTrackingNumber($dataType);
  }

  private function assertShipmentCanBeRetrieved(string $trackingNumber): void
  {
    $response = $this->client->getShipment($trackingNumber);

    $this->assertSuccessfulResponse($response, 'Shipment detail response');
    $this->assertResponseContainsTrackingNumber($response, $trackingNumber);
  }

  private function assertShipmentCanBeRetrievedByReference(): void
  {
    $response = $this->client->getShipment($this->reference);

    $this->assertSuccessfulResponse($response, 'Shipment reference response');
    $this->assertResponseContainsReference($response, $this->reference);
  }

  private function assertShipmentAppearsInList(string $trackingNumber): void
  {
    $response = $this->client->listShipments([
        'reference' => $this->reference,
        'from_date' => (new DateTimeImmutable('today'))
            ->format('Y-m-d'),
        'per_page' => 10,
        'page' => 1,
    ]);

    $this->assertListContainsShipment($response, $trackingNumber, $this->reference);
  }

  private function assertLabelCanBeDownloaded(string $trackingNumber): void
  {
    $content = $this->client->getShipmentLabelContent($trackingNumber);

    $this->assertPdfContent($content);
  }

  private function assertTrackingCanBeRetrieved(string $trackingNumber): void
  {
    $response = $this->client->getShipmentTracking($trackingNumber);

    $this->assertSuccessfulResponse($response, 'Tracking response');

    $data = $response['data'] ?? null;
    self::assertIsArray($data, 'The tracking response data must be an array.');
  }

  private function cancelAndAssertShipment(string $trackingNumber): void
  {
    $response = $this->client->cancelShipment($trackingNumber);

    $this->assertSuccessfulResponse($response, 'Shipment cancellation response');
  }

  /**
   * @return array<string, mixed>
   */
  private function createPayload(): array
  {
    return array_replace(
      Fixtures::shipmentData(true),
      [
        'reference' => $this->reference,
        'content_value' => 100.00,
        'content_description' => 'Sandbox regression test goods',
        'services' => null,
      ]
    );
  }

  /**
   * @param array<string, mixed> $response
   */
  private function assertSuccessfulResponse(array $response, string $responseName): void
  {
    self::assertArrayHasKey(
      'success',
      $response,
      sprintf('%s does not contain a success field.', $responseName)
    );

    self::assertTrue($response['success'], sprintf('%s was not successful.', $responseName));
  }

  /**
   * @param array<string, mixed> $shipment
   */
  private function extractTrackingNumber(array $shipment): string
  {
    $trackingNumber = $shipment['tracking_number'] ?? null;
    self::assertIsString($trackingNumber, 'The tracking number must be a string.');
    self::assertNotSame('', trim($trackingNumber), 'The tracking number must not be empty.');

    return trim($trackingNumber);
  }

  /**
   * @param string $type The type of data to extract from the response.
   * @param array<string, mixed> $response
   */
  private function assertSandboxResponse(string $type, array $response): void
  {
    $meta = $response['meta'] ?? null;

    self::assertIsArray(
      $meta,
      'The created shipment does not contain meta information.'
    );

    self::assertArrayHasKey(
      'request_id',
      $meta,
      'The created shipment does not contain meta.request_id.'
    );
  }

  /**
   * @param string $type The type of data to extract from the response.
   * @param array<string, mixed> $response
   *
   * @return array<string, mixed>
   */
  private function extractResponseData(string $type, array $response): array
  {
    $data = $response['data'] ?? null;
    self::assertIsArray($data, 'The response data must be an array.');

    $dataType = $data[$type] ?? $data;
    self::assertIsArray($dataType, "The {$type} data must be an array.");

    return $dataType;
  }

  /**
   * @param array<string, mixed> $response
   */
  private function assertResponseContainsTrackingNumber(
    array $response,
    string $expectedTrackingNumber
  ): void {
    $shipment = $this->extractResponseData('shipment', $response);

    self::assertSame(
      $expectedTrackingNumber,
      $shipment['tracking_number'] ?? null,
      'The shipment response contains an unexpected tracking number.'
    );
  }

  /**
   * @param array<string, mixed> $response
   */
  private function assertResponseContainsReference(
    array $response,
    string $expectedReference
  ): void {
    $shipment = $this->extractResponseData('shipment', $response);

    self::assertSame(
      $expectedReference,
      $shipment['reference'] ?? null,
      'The shipment response contains an unexpected customer reference.'
    );
  }

  /**
   * @param array<string, mixed> $response
   */
  private function assertListContainsShipment(
    array $response,
    string $trackingNumber,
    string $reference
  ): void {
    $shipments = $this->extractShipmentList($response);

    $matchingShipments = array_filter(
      $shipments,
      static function (mixed $shipment) use ($trackingNumber, $reference): bool {
        if (!is_array($shipment)) {
          return false;
        }

        return ($shipment['tracking_number'] ?? null) === $trackingNumber
          && ($shipment['reference'] ?? null) === $reference;
      }
    );

    self::assertNotEmpty(
      $matchingShipments,
      sprintf(
        'The shipment list does not contain tracking number "%s" '
          . 'with reference "%s".',
        $trackingNumber,
        $reference
      )
    );
  }

  /**
   * Extract shipments while tolerating common paginated response envelopes.
   *
   * @param array<string, mixed> $response
   *
   * @return array<int, mixed>
   */
  private function extractShipmentList(array $response): array
  {
    $data = $response['data'] ?? null;

    self::assertIsArray($data, 'The shipment list response data must be an array.');

    if (array_is_list($data)) {
      return $data;
    }

    foreach (['shipments', 'items', 'data'] as $key) {
      $items = $data[$key] ?? null;

      if (is_array($items) && array_is_list($items)) {
        return $items;
      }
    }

    self::fail('The shipment list response does not contain a supported list envelope.');
  }

  private function assertPdfContent(string $content): void
  {
    self::assertNotSame(
      '',
      $content,
      'The decoded label must not be empty.'
    );

    self::assertStringStartsWith(
      '%PDF-',
      $content,
      'The decoded label does not have a PDF file signature.'
    );

    self::assertGreaterThan(
      100,
      strlen($content),
      'The decoded PDF label is unexpectedly small.'
    );
  }

  private function cancelSandboxShipment(string $trackingNumber, ?Throwable &$testFailure): void
  {
    try {
      $cancelResponse = $this->client->cancelShipment($trackingNumber);

      $this->assertSuccessfulResponse($cancelResponse, 'Shipment cleanup cancellation response');
    } catch (Throwable $cleanupException) {
      $message = sprintf(
        "Sandbox cleanup failed for tracking number %s: %s\n",
        $trackingNumber,
        $cleanupException->getMessage()
      );

      fwrite(STDERR, $message);

      /*
       * Preserve the original test failure. If the test itself passed,
       * a failed cancellation becomes the test failure.
       */
      if ($testFailure === null) {
        $testFailure = $cleanupException;
      }
    }
  }
}
