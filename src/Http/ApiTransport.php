<?php

namespace NexCargo\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ResponseException;
use NexCargo\ApiException;
use Throwable;

final class ApiTransport
{
  public function __construct(private readonly ClientInterface $httpClient)
  {
  }

  /**
   * Send a request and decode its JSON response, throwing on any HTTP or API error.
   *
   * @param array<string, mixed> $options
   * @return array<string, mixed>
   * @throws ApiException
   */
  public function requestJson(string $method, string $uri, array $options, string $resource): array
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

    if ($isHttpError || (array_key_exists('success', $payload) && $payload['success'] !== true)) {
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
