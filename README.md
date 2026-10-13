# NexCargo PHP SDK

A PHP 8.2+ SDK for the Camel Cloud API / NexCargo carrier integration using Guzzle.

[![Latest Stable Version](https://img.shields.io/github/v/release/schoppax/nextcargo-php-sdk?label=Stable)](https://github.com/schoppax/nextcargo-php-sdk/releases)
[![Linter](https://github.com/schoppax/nextcargo-php-sdk/actions/workflows/linter.yml/badge.svg?branch=main)](https://github.com/schoppax/nextcargo-php-sdk/actions/workflows/linter.yml)
[![Code style](https://github.com/schoppax/nextcargo-php-sdk/actions/workflows/phpstan.yml/badge.svg?branch=main)](https://github.com/schoppax/nextcargo-php-sdk/actions/workflows/phpstan.yml)
[![Test](https://github.com/schoppax/nextcargo-php-sdk/actions/workflows/test.yml/badge.svg?branch=main)](https://github.com/schoppax/nextcargo-php-sdk/actions/workflows/test.yml)
[![Coverage Status](https://raw.githubusercontent.com/schoppax/nextcargo-php-sdk/badges/coverage.svg)](https://github.com/schoppax/nextcargo-php-sdk)

![Minimum PHP Version](https://img.shields.io/packagist/dependency-v/schoppax/nexcargo-php-sdk/php.svg)
![Tested on PHP 8.2 to 8.5](https://img.shields.io/badge/tested%20on-PHP%208.2%20|%208.3%20|%208.4%20|%208.5-brightgreen.svg?maxAge=2419200)

## Installation

Install the package from Packagist:

```bash
composer require schoppax/nexcargo-php-sdk
```

For local development, install the dependencies and configure the project with:

```bash
composer setup
```

## API version

This SDK follows NexCargo documentation version 2.11.

### Endpoints

| Method | Endpoint | Client method |
|--------|----------|---------------|
| POST | `/api/v1/shipments` | `createShipment()` |
| POST | `/api/v1/shipments/quote` | `quoteShipment()` |
| GET | `/api/v1/shipments` | `listShipments()` |
| GET | `/api/v1/shipments/{identifier}` | `getShipment()` |
| GET | `/api/v1/shipments/{id}/label` | `getShipmentLabelContent()` |
| GET | `/api/v1/shipments/{id}/tracking` | `getShipmentTracking()` |
| DELETE | `/api/v1/shipments/{id}` | `cancelShipment()` |

All methods except `getShipmentLabelContent()` return the full decoded JSON response as an associative array; no response fields are dropped.

### Client

Pass the API key and the host base URL (without `/api/v1`). Use the sandbox host and a sandbox key for test bookings and your production host and key for live use; the SDK does not switch environments itself.

```php
use NexCargo\Client;

$client = new Client('your-api-key', 'https://api.example.com');
```

Requests are sent with `Authorization: Bearer <key>`, `Accept: application/json` and `Content-Type: application/json`.

### Shipment creation

Requests accept model objects or plain arrays.

```php
use NexCargo\Models\Address;
use NexCargo\Models\Dimensions;
use NexCargo\Models\Parcel;
use NexCargo\Models\ShipmentRequest;

$pickup = new Address([
  'name' => 'Musterfirma GmbH',
  'street' => 'Musterstraße',
  'house_number' => '123',
  'zipcode' => '50667',
  'city' => 'Köln',
  'country' => 'DE',
]);

$delivery = new Address([
  'name' => 'Empfänger GmbH',
  'street' => 'Beispielweg',
  'house_number' => '456',
  'zipcode' => '10115',
  'city' => 'Berlin',
  'country' => 'DE',
]);

$package = new Parcel([
  'weight' => 15.5,
  'dimensions' => new Dimensions([
    'length' => 60,
    'width' => 40,
    'height' => 30
  ]),
]);

$payload = [
  'carrier' => 'auto',
  'pickup_date' => '2026-01-15',
  'pickup_time_from' => '09:00',
  'pickup_time_to' => '12:00',
  'delivery_date' => '2026-01-16',
  'delivery_time_from' => '08:00',
  'delivery_time_to' => '18:00',
  'pickup' => $pickup,
  'delivery' => $delivery,
  'packages' => [$package],
];

$response = $client->createShipment(new ShipmentRequest($payload));
```

Optional fields are:

- `Address`: `name2`, `phone`, `email`, `notes`, `contact_person`
- `Parcel`: `quantity`, `type`, `description`
- Request: `reference`, `content_value`, `content_description`, `services`, `pallet`

The SDK does not truncate or validate values (for example the documented limits of 50 characters for names and 255 for notes); the server validates.

### Quote request

The quote endpoint uses the same request shape as shipment creation, but the carrier field is optional.

```php
use NexCargo\Models\QuoteRequest;

unset($payload['carrier']);
$quote = $client->quoteShipment(new QuoteRequest($payload));
```

### List and fetch shipments

```php
$list = $client->listShipments([
  'status' => 'IN_DELIVERY',
  'carrier' => 'ctl',
  'from_date' => '2026-01-01',
  'to_date' => '2026-01-31',
  'reference' => 'ORDER-1',
  'per_page' => 25,
  'page' => 1,
]);

$shipment = $client->getShipment('ORDER-1/A');
```

Documented `status` values are:

- `UNPROCESSED`,
- `STATION_OUTBOUND`,
- `IN_DELIVERY`,
- `DELIVERED`,
- `CANCELLED`

Values are passed through as given, not validated.

Only `status`, `carrier`, `from_date`, `to_date`, `reference`, `per_page` and `page` are sent. Other keys are ignored, `null` values are dropped and `0` is kept.

The identifier may be the internal shipment ID, tracking number or customer reference. It is URL-encoded by the SDK.

- `tracking_number` is the recommended persistent key. Store it.
- The internal shipment `id` can change after a carrier reconciliation.
- `reference` response can be `null`.
- Carrier references may be contained in `references`.

### Label, tracking, cancellation

```php
$pdfContents = $client->getShipmentLabelContent($trackingNumber);
file_put_contents('shipment-label.pdf', $pdfContents);

$tracking = $client->getShipmentTracking($trackingNumber);
$cancelled = $client->cancelShipment($trackingNumber);
```

`getShipmentLabelContent()` returns the decoded PDF bytes and throws `ApiException` if:

- the label is missing or `null`;
- the format or encoding is not PDF/base64;
- the content is empty;
- the base64 content is invalid.

The SDK does not save files.

Cancelling a shipment that cannot be cancelled returns the API error `CANCEL_NOT_SUPPORTED` with HTTP status `409`.

### CTL pallet options

Add `pallet` to a shipment or quote request as a `PalletOptions` object or an array.

Supported fields are:

- `product`
- `private_recipient`
- `avis`
- `fixed_date`
- `fixed_time`
- `services`
- `insurance`
- `insurance_value`
- `driver_avis_pickup`

```php
use NexCargo\Models\PalletOptions;

$payload['carrier'] = 'ctl';
$payload['pallet'] = new PalletOptions([
  'private_recipient' => false,
  'insurance' => true,
  'insurance_value' => 500,
  'services' => ['avis_email', 'zf_buchung'],
]);
```

`pallet.services` values:

| Value | Meaning |
|-------|---------|
| `palettentausch` | Pallet exchange (`Europalette`/`Industriepalette` only) |
| `tausch_gitterbox` | Gitterbox exchange (`Gitterbox` only) |
| `zf_buchung` | Time-slot booking |
| `avis_telefon` | Phone notification |
| `avis_email` | Email notification |
| `unterschrift_original_ls` | Signed original delivery note returned |
| `gefahrgut` | Dangerous goods |

For CTL, `packages[].type` (`Parcel` `type`) defaults to a one-way pallet `Einwegpalette`. Allowed values (long name or code): `Europalette`/`FP`, `Einwegpalette`/`EP`, `Industriepalette`/`E2`, `E1`, `Halbpalette`/`HP`, `Gitterbox`/`GP`, `CHEP`/`CP`, `Kiste`/`KI`, `IBC`/`IB`, `Karton`/`KT`, `Paket`/`PA`, `Rollcontainer`/`RC`, `Fass`/`FA`, `Sack`/`SA`.

```php
$package = new Parcel(['weight' => 250.0, 'type' => 'Europalette']);
```

The SDK passes these values through without validation.
The SDK does not apply CTL rules, set the carrier or correct dimensions, weights or products. Server-side data such as `data.adjustments` and `meta.pallet` is returned unchanged.

## Error handling

Failed requests throw `NexCargo\ApiException`.

```php
use NexCargo\ApiException;

try {
  $client->createShipment(new ShipmentRequest($payload));
} catch (ApiException $e) {
  $e->getStatusCode();      // HTTP status, if available
  $e->getApiCode();         // e.g. VALIDATION_ERROR, PALLET_RULE_VIOLATION
  $e->getMessage();
  $e->getDetails();
  $e->getRequestId();
  $e->getTimestamp();
  $e->getResponseBody();    // decoded body, or null
  $e->getRawResponseBody();
  $e->getResponseHeaders();
  $e->getPrevious();        // original Guzzle exception, if any
}
```

Invalid or empty error bodies do not cause a second error.

## Rate limits

On HTTP 429 (`RATE_LIMIT_EXCEEDED`), `getRetryAfter()` returns `Retry-After` from the header, falling back to `error.retry_after` from the body. `getRateLimitHeaders()` returns the `X-RateLimit-*` response headers.

The SDK never retries automatically, and `POST /shipments` must never be retried blindly because it can create duplicate shipments. Check with `listShipments(['reference' => ...])` before repeating a booking.

## Testing

Run the regular unit test suite with:

```bash
composer test
```

Run all unit tests including lint and quality with:

```bash
composer test:all
```

### Sandbox integration test

The Sandbox integration test performs a complete shipment lifecycle against the configured NexCargo Sandbox API:

1. requests a quote
2. creates a shipment
3. retrieves the shipment by tracking number and customer reference
4. searches for the shipment using the list endpoint
5. downloads and validates the PDF label
6. retrieves tracking information
7. cancels the created shipment.

The test performs real write operations against the configured API. Always use a Sandbox API key and Sandbox base URL.

#### Configuration

Copy the PHPUnit configuration template before running the test:

```bash
cp phpunit.xml.dist phpunit.xml
```

On Windows PowerShell:

```powershell
Copy-Item phpunit.xml.dist phpunit.xml
```

Add or update the following environment variables in the `<php>` section of your local `phpunit.xml`:

```xml
<php>
  <env name="NEXCARGO_SANDBOX_API_KEY" value="your-sandbox-api-key"/>
  <env name="NEXCARGO_BASE_URI" value="https://your-sandbox-host.example.com"/>
  <env name="NEXCARGO_ALLOW_WRITE" value="true"/>
</php>
```

The base URI must contain the Sandbox host without the `/api/v1` path.

`NEXCARGO_ALLOW_WRITE` is an explicit safety switch. The Sandbox lifecycle test is skipped unless it is set to `true`.

> [!WARNING]
> The local `phpunit.xml` can contain sensitive credentials and must not be committed. Only `phpunit.xml.dist`, without real credentials, should be version controlled.

The version-controlled `phpunit.xml.dist` should contain the required variable names without real credentials and keep write access disabled by default:

```xml
<php>
  <env name="NEXCARGO_SANDBOX_API_KEY" value=""/>
  <env name="NEXCARGO_BASE_URI" value=""/>
  <env name="NEXCARGO_ALLOW_WRITE" value="false"/>
</php>
```

Alternatively, provide the credentials through environment variables.

#### Running the Sandbox test

Run the configured Composer script:

```bash
composer test:sandbox
```

The test belongs to both the `integration` and `sandbox` PHPUnit groups.

The test is skipped when one of the required variables is missing or when `NEXCARGO_ALLOW_WRITE` is not set to `true`.

The test attempts to cancel the created Sandbox shipment even if a later assertion or API request fails. If cleanup also fails, the tracking number and cleanup error are written to STDERR for manual investigation.
