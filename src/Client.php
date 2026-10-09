<?php

namespace NexCargo;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ResponseException;
use NexCargo\Models\QuoteRequest;
use NexCargo\Models\ShipmentRequest;
use Throwable;

class Client
{
  private string $apiKey;
  private string $baseUri;
  private GuzzleClient $httpClient;

  /**
   * Create a new API client.
   */
  public function __construct(
    string $apiKey,
    string $baseUri = 'https://api.camelcloud.example',
    ?GuzzleClient $httpClient = null
  ) {
    $this->apiKey = $apiKey;
    $this->baseUri = rtrim($baseUri, '/') . '/';
    $this->httpClient = $httpClient ?? new GuzzleClient([
      'base_uri' => $this->baseUri,
      'headers' => [
        'Authorization' => 'Bearer ' . $this->apiKey,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
      ],
    ]);
  }

  /**
   * Create a shipment.
   *
   * @param ShipmentRequest|array<string, mixed> $payload
   * @return array<string, mixed>
   * @throws ApiException
   */
  public function createShipment(ShipmentRequest|array $payload): array
  {
    $request = $payload instanceof ShipmentRequest ? $payload : new ShipmentRequest($payload);

    $payload = $this->requestJson(
      'POST',
      '/api/v1/shipments',
      ['json' => $request->toArray()],
      'shipment creation'
    );

    if (!isset($payload['data']) || !is_array($payload['data'])) {
      throw new ApiException('The create shipment response is missing shipment data.');
    }

    return $payload;
  }

  /**
   * List shipments, optionally filtered by the supported query parameters.
   *
   * @param array<string, mixed> $filters
   * @return array<string, mixed>
   * @throws ApiException
   */
  public function listShipments(array $filters = []): array
  {
    $allowedFilters = ['status', 'carrier', 'from_date', 'to_date', 'reference', 'per_page', 'page'];
    $query = array_filter(
      array_intersect_key($filters, array_flip($allowedFilters)),
      static fn ($value) => $value !== null
    );

    return $this->requestJson(
      'GET',
      '/api/v1/shipments',
      $query === [] ? [] : ['query' => $query],
      'shipment list'
    );
  }

  /**
   * Get a shipment by its identifier.
   *
   * @return array<string, mixed>
   * @throws ApiException
   */
  public function getShipment(string|int $identifier): array
  {
    return $this->requestJson(
      'GET',
      '/api/v1/shipments/' . rawurlencode((string) $identifier),
      [],
      'shipment retrieval'
    );
  }

  /**
   * Download and decode the PDF shipment label.
   *
   * @return string Decoded PDF file contents.
   * @throws ApiException
   */
  public function getShipmentLabelContent(string $id): string
  {
    $payload = $this->requestJson('GET', '/api/v1/shipments/' . $id . '/label', [], 'shipment label');
    $data = $payload['data'] ?? null;
    $label = is_array($data) ? ($data['label'] ?? null) : null;

    if (!is_array($label)) {
      throw new ApiException('The shipment label response is missing the label data.');
    }

    if (($label['format'] ?? null) !== 'pdf' || ($label['encoding'] ?? null) !== 'base64') {
      throw new ApiException('The shipment label response has an unsupported format or encoding.');
    }

    $content = $label['content'] ?? null;
    if (!is_string($content)) {
      throw new ApiException('The shipment label response is missing base64 content.');
    }

    $pdfContents = base64_decode($content, true);

    if ($pdfContents === false) {
      throw new ApiException('The shipment label content is not valid base64.');
    }

    return $pdfContents;
  }

  /**
   * Get tracking information for a shipment.
   *
   * @return array<string, mixed>
   * @throws ApiException
   */
  public function getShipmentTracking(string $id): array
  {
    $payload = $this->requestJson('GET', '/api/v1/shipments/' . $id . '/tracking', [], 'shipment tracking');

    if (!isset($payload['data']) || !is_array($payload['data'])) {
      throw new ApiException('The shipment tracking response is missing tracking data.');
    }

    return $payload;
  }

  /**
   * Cancel a shipment.
   *
   * @return array<string, mixed>
   * @throws ApiException
   */
  public function cancelShipment(string $id): array
  {
    return $this->requestJson('DELETE', '/api/v1/shipments/' . $id, [], 'shipment cancellation');
  }

  /**
   * Request a shipping quote.
   *
   * @param QuoteRequest|array<string, mixed> $payload
   * @return array<string, mixed>
   * @throws ApiException
   */
  public function quoteShipment(QuoteRequest|array $payload): array
  {
    $request = $payload instanceof QuoteRequest ? $payload : new QuoteRequest($payload);

    return $this->requestJson(
      'POST',
      '/api/v1/shipments/quote',
      ['json' => $request->toArray()],
      'shipment quote'
    );
  }

  /**
   * Send a request and decode its JSON response, throwing on any HTTP or API error.
   *
   * @param array<string, mixed> $options
   * @return array<string, mixed>
   * @throws ApiException
   */
  private function requestJson(string $method, string $uri, array $options, string $resource): array
  {
    $previousException = null;
    try {
      $response = $this->httpClient->request($method, $uri, $options);
    } catch (GuzzleException $exception) {
      if (!$exception instanceof ResponseException) {
        throw new ApiException($exception->getMessage(), $exception->getCode(), $exception);
      }

      $previousException = $exception;
      $response = $exception->getResponse();
    }

    $statusCode = $response->getStatusCode();
    $isHttpError = $statusCode < 200 || $statusCode >= 300;
    $responseHeaders = $response->getHeaders();
    $rawResponseBody = (string) $response->getBody();

    try {
      $payload = json_decode($rawResponseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
      if ($isHttpError) {
        throw new ApiException(
          "The {$resource} request failed with HTTP status {$statusCode}.",
          $statusCode,
          $previousException ?? $exception,
          responseHeaders: $responseHeaders,
          statusCode: $statusCode,
          rawResponseBody: $rawResponseBody
        );
      }

      throw new ApiException(
        "The {$resource} response contains invalid JSON.",
        0,
        $exception,
        responseHeaders: $responseHeaders,
        statusCode: $statusCode,
        rawResponseBody: $rawResponseBody
      );
    }

    if (!is_array($payload)) {
      throw new ApiException(
        "The {$resource} response must contain a JSON object.",
        $isHttpError ? $statusCode : 0,
        $previousException,
        responseHeaders: $responseHeaders,
        responseBody: $payload,
        statusCode: $statusCode,
        rawResponseBody: $rawResponseBody
      );
    }

    if ($isHttpError || ($payload['success'] ?? null) !== true) {
      throw $this->buildErrorException(
        $payload,
        $resource,
        $statusCode,
        $isHttpError,
        $responseHeaders,
        $rawResponseBody,
        $previousException
      );
    }

    return $payload;
  }

  /**
   * Build an ApiException describing a failed or unsuccessful response.
   *
   * @param array<string, mixed> $payload
   * @param array<string, array<int, string>> $responseHeaders
   */
  private function buildErrorException(
    array $payload,
    string $resource,
    int $statusCode,
    bool $isHttpError,
    array $responseHeaders,
    string $rawResponseBody,
    ?Throwable $previousException
  ): ApiException {
    $error = $payload['error'] ?? null;

    if (!is_array($error)) {
      $message = $isHttpError
        ? "The {$resource} request failed with HTTP status {$statusCode}."
        : "The {$resource} response was not successful.";

      return new ApiException(
        $message,
        $statusCode,
        $previousException,
        responseHeaders: $responseHeaders,
        responseBody: $payload,
        statusCode: $statusCode,
        rawResponseBody: $rawResponseBody
      );
    }

    $message = is_string($error['message'] ?? null)
      ? $error['message']
      : "The {$resource} request failed.";
    $apiCode = is_string($error['code'] ?? null) ? $error['code'] : null;
    $details = is_array($error['details'] ?? null) ? $error['details'] : [];
    $requestId = $this->stringMetadata($payload, $error, 'request_id');
    $timestamp = $this->stringMetadata($payload, $error, 'timestamp');
    $retryAfter = $this->getRetryAfter($responseHeaders, $error);

    return new ApiException(
      $message,
      $statusCode,
      $previousException,
      $apiCode,
      $details,
      $requestId,
      $timestamp,
      $retryAfter,
      $responseHeaders,
      $payload,
      $statusCode,
      $rawResponseBody
    );
  }

  /**
   * @param array<string, mixed> $payload
   * @param array<string, mixed> $error
   */
  private function stringMetadata(array $payload, array $error, string $key): ?string
  {
    $value = $payload[$key] ?? $error[$key] ?? null;
    return is_string($value) ? $value : null;
  }

  /**
   * @param array<string, array<int, string>> $responseHeaders
   * @param array<string, mixed> $error
   */
  private function getRetryAfter(array $responseHeaders, array $error): int|float|string|null
  {
    foreach ($responseHeaders as $name => $values) {
      if (strtolower($name) === 'retry-after' && isset($values[0]) && $values[0] !== '') {
        return $values[0];
      }
    }

    $retryAfter = $error['retry_after'] ?? null;
    return is_int($retryAfter) || is_float($retryAfter) || is_string($retryAfter) ? $retryAfter : null;
  }
}
