<?php

namespace NexCargo;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use NexCargo\Models\QuoteRequest;
use NexCargo\Models\ShipmentRequest;
use NexCargo\Http\ApiTransport;

class Client
{
  private ApiTransport $transport;

  /**
   * Create a new API client.
   */
  public function __construct(
    string $apiKey,
    string $baseUri = 'https://api.camelcloud.example',
    ?ClientInterface $httpClient = null
  ) {
    $baseUri = rtrim($baseUri, '/') . '/';
    $httpClient ??= new GuzzleClient([
      'base_uri' => $baseUri,
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
      ],
    ]);
    $this->transport = new ApiTransport($httpClient);
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

    $payload = $this->transport->requestJson(
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

    return $this->transport->requestJson(
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
    return $this->transport->requestJson(
      'GET',
      $this->shipmentPath($identifier),
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
  public function getShipmentLabelContent(string|int $identifier): string
  {
    $payload = $this->transport->requestJson('GET', $this->shipmentPath($identifier, 'label'), [], 'shipment label');
    $data = $payload['data'] ?? null;
    $label = is_array($data) ? ($data['label'] ?? null) : null;

    if (!is_array($label)) {
      throw new ApiException('The shipment label response is missing the label data.');
    }

    if (($label['format'] ?? null) !== 'pdf' || ($label['encoding'] ?? null) !== 'base64') {
      throw new ApiException('The shipment label response has an unsupported format or encoding.');
    }

    $content = $label['content'] ?? null;
    if (!is_string($content) || $content === '') {
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
  public function getShipmentTracking(string|int $identifier): array
  {
    $payload = $this->transport->requestJson(
      'GET',
      $this->shipmentPath($identifier, 'tracking'),
      [],
      'shipment tracking'
    );

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
  public function cancelShipment(string|int $identifier): array
  {
    return $this->transport->requestJson('DELETE', $this->shipmentPath($identifier), [], 'shipment cancellation');
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

    return $this->transport->requestJson(
      'POST',
      '/api/v1/shipments/quote',
      ['json' => $request->toArray()],
      'shipment quote'
    );
  }

  private function shipmentPath(string|int $identifier, ?string $suffix = null): string
  {
    $path = '/api/v1/shipments/' . rawurlencode((string) $identifier);

    return $suffix === null ? $path : $path . '/' . $suffix;
  }
}
