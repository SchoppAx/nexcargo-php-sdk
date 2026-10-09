<?php

namespace NexCargo;

use RuntimeException;
use Throwable;

class ApiException extends RuntimeException
{
  private ?string $apiCode;

  /** @var array<string, mixed> */
  private array $details;
  private ?string $requestId;
  private ?string $timestamp;
  private int|float|string|null $retryAfter;

  /** @var array<string, array<int, string>> */
  private array $responseHeaders;

  private mixed $responseBody;
  private ?int $statusCode;
  private ?string $rawResponseBody;

  /**
   * @param array<string, mixed> $details
   * @param array<string, array<int, string>> $responseHeaders
   */
  public function __construct(
    string $message = '',
    int $code = 0,
    ?Throwable $previous = null,
    ?string $apiCode = null,
    array $details = [],
    ?string $requestId = null,
    ?string $timestamp = null,
    int|float|string|null $retryAfter = null,
    array $responseHeaders = [],
    mixed $responseBody = null,
    ?int $statusCode = null,
    ?string $rawResponseBody = null
  ) {
    parent::__construct($message, $code, $previous);
    $this->apiCode = $apiCode;
    $this->details = $details;
    $this->requestId = $requestId;
    $this->timestamp = $timestamp;
    $this->retryAfter = $retryAfter;
    $this->responseHeaders = $responseHeaders;
    $this->responseBody = $responseBody;
    $this->statusCode = $statusCode;
    $this->rawResponseBody = $rawResponseBody;
  }

  public function getApiCode(): ?string
  {
    return $this->apiCode;
  }

  /**
   * @return array<string, mixed>
   */
  public function getDetails(): array
  {
    return $this->details;
  }

  public function getStatusCode(): ?int
  {
    return $this->statusCode;
  }

  public function getRequestId(): ?string
  {
    return $this->requestId;
  }

  public function getTimestamp(): ?string
  {
    return $this->timestamp;
  }

  public function getRetryAfter(): int|float|string|null
  {
    return $this->retryAfter;
  }

  /**
   * @return array<string, array<int, string>>
   */
  public function getResponseHeaders(): array
  {
    return $this->responseHeaders;
  }

  /**
   * @return array<string, array<int, string>>
   */
  public function getRateLimitHeaders(): array
  {
    return array_filter(
      $this->responseHeaders,
      static fn (array $values, string $name): bool => str_starts_with(strtolower($name), 'x-ratelimit-'),
      ARRAY_FILTER_USE_BOTH
    );
  }

  public function getResponseBody(): mixed
  {
    return $this->responseBody;
  }

  public function getRawResponseBody(): ?string
  {
    return $this->rawResponseBody;
  }
}
