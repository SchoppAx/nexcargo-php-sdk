<?php

namespace NexCargo\Tests\Models;

use NexCargo\Models\QuoteRequest;
use NexCargo\Models\Address;
use NexCargo\Models\Dimensions;
use NexCargo\Models\Parcel;
use NexCargo\Models\PalletOptions;
use NexCargo\Tests\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuoteRequestTest extends TestCase
{
  /** @return array<string, array{0: string}> */
  public static function requiredFieldsProvider(): array
  {
    return [
      'pickup_date' => ['pickup_date'],
      'pickup_time_from' => ['pickup_time_from'],
      'pickup_time_to' => ['pickup_time_to'],
      'delivery_date' => ['delivery_date'],
      'delivery_time_from' => ['delivery_time_from'],
      'delivery_time_to' => ['delivery_time_to'],
      'pickup' => ['pickup'],
      'delivery' => ['delivery'],
      'packages' => ['packages'],
    ];
  }

  #[DataProvider('requiredFieldsProvider')]
  public function testThrowsWhenRequiredFieldIsMissing(string $field): void
  {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('QuoteRequest requires ' . $field . '.');

    $data = Fixtures::quoteData();
    unset($data[$field]);
    new QuoteRequest($data);
  }

  public function testToArrayReturnsNormalizedData(): void
  {
    $request = new QuoteRequest(Fixtures::quoteData());
    $result = $request->toArray();

    $this->assertSame(Fixtures::quoteData(), $result);
    $this->assertSame('2026-01-15', $result['pickup_date']);
    $this->assertIsArray($result['pickup']);
    $this->assertIsArray($result['packages']);
  }

  public function testToArraySupportsCarrierAndOptionalFieldsWithNestedModels(): void
  {
    $data = Fixtures::quoteData();
    $data['carrier'] = 'auto';
    $data['reference'] = 'QUOTE-456';
    $data['content_value'] = 0;
    $data['content_description'] = 'Replacement items';
    $data['services'] = ['notification' => true];
    $data['pickup'] = new Address([
      ...Fixtures::addressData(),
      'name2' => 'Dispatch',
      'email' => 'pickup@example.com',
      'notes' => 'Loading bay',
      'contact_person' => 'Pickup Contact',
    ]);
    $data['delivery'] = new Address([
      ...Fixtures::addressData(),
      'name2' => 'Receiving',
      'email' => 'delivery@example.com',
      'notes' => 'Ring bell',
      'contact_person' => 'Delivery Contact',
    ]);
    $data['packages'] = [new Parcel([
      'weight' => 10.0,
      'quantity' => 3,
      'type' => 'PA',
      'description' => 'Replacement items',
      'dimensions' => new Dimensions(['length' => 50, 'width' => 30, 'height' => 20]),
    ])];

    $result = (new QuoteRequest($data))->toArray();

    $expected = Fixtures::quoteData();
    $expected['carrier'] = 'auto';
    $expected['reference'] = 'QUOTE-456';
    $expected['content_value'] = 0;
    $expected['content_description'] = 'Replacement items';
    $expected['services'] = ['notification' => true];
    $expected['pickup'] = [
      'name' => 'Test GmbH',
      'name2' => 'Dispatch',
      'street' => 'Teststraße',
      'house_number' => '1',
      'zipcode' => '50667',
      'city' => 'Köln',
      'country' => 'DE',
      'phone' => '+49 123 4567890',
      'email' => 'pickup@example.com',
      'notes' => 'Loading bay',
      'contact_person' => 'Pickup Contact',
    ];
    $expected['delivery'] = [
      'name' => 'Test GmbH',
      'name2' => 'Receiving',
      'street' => 'Teststraße',
      'house_number' => '1',
      'zipcode' => '50667',
      'city' => 'Köln',
      'country' => 'DE',
      'phone' => '+49 123 4567890',
      'email' => 'delivery@example.com',
      'notes' => 'Ring bell',
      'contact_person' => 'Delivery Contact',
    ];
    $expected['packages'] = [[
      'weight' => 10.0,
      'quantity' => 3,
      'type' => 'PA',
      'description' => 'Replacement items',
      'length' => 50.0,
      'width' => 30.0,
      'height' => 20.0,
    ]];

    $this->assertSame($expected, $result);
  }

  public function testToArraySupportsCarrierOmissionAndEmptyServices(): void
  {
    $data = Fixtures::quoteData();
    $data['services'] = [];

    $result = (new QuoteRequest($data))->toArray();

    $this->assertArrayNotHasKey('carrier', $result);
    $this->assertSame([], $result['services']);
    $this->assertSame('2026-01-15', $result['pickup_date']);
    $this->assertSame('09:00', $result['pickup_time_from']);
    $this->assertSame('12:00', $result['pickup_time_to']);
    $this->assertSame('2026-01-16', $result['delivery_date']);
    $this->assertSame('08:00', $result['delivery_time_from']);
    $this->assertSame('18:00', $result['delivery_time_to']);
  }

  public function testToArrayNormalizesPalletOptions(): void
  {
    $data = Fixtures::quoteData();
    $data['pallet'] = new PalletOptions([
      'product' => 'PA',
      'avis' => false,
      'insurance_value' => 0,
      'driver_avis_pickup' => false,
      'services' => ['tail_lift'],
    ]);

    $result = (new QuoteRequest($data))->toArray();

    $this->assertSame([
      'product' => 'PA',
      'avis' => false,
      'services' => ['tail_lift'],
      'insurance_value' => 0.0,
      'driver_avis_pickup' => false,
    ], $result['pallet']);
  }

  public function testToArrayOmitsNullOptionalFields(): void
  {
    $data = Fixtures::quoteData() + [
      'reference' => null,
      'content_value' => null,
      'content_description' => null,
      'services' => null,
    ];

    $result = (new QuoteRequest($data))->toArray();

    $this->assertArrayNotHasKey('carrier', $result);
    $this->assertNull($result['reference']);
    $this->assertNull($result['content_value']);
    $this->assertNull($result['content_description']);
    $this->assertNull($result['services']);
  }

  public function testNormalizesNestedSelfInstance(): void
  {
    $inner = new QuoteRequest(Fixtures::quoteData());
    $data = Fixtures::quoteData();
    $data['related'] = $inner;

    $result = (new QuoteRequest($data))->toArray();

    $this->assertIsArray($result['related']);
    $this->assertSame('2026-01-15', $result['related']['pickup_date']);
  }

  public function testNormalizesArrayObject(): void
  {
    $data = Fixtures::quoteData();
    $data['metadata'] = new \ArrayObject(['source' => 'test']);

    $result = (new QuoteRequest($data))->toArray();

    $this->assertSame(['source' => 'test'], $result['metadata']);
  }

  public function testNormalizesGenericObjectWithToArrayMethod(): void
  {
    $data = Fixtures::quoteData();
    $data['pickup'] = new Address(Fixtures::addressData());

    $result = (new QuoteRequest($data))->toArray();

    $this->assertSame(Fixtures::addressData(), $result['pickup']);
  }
}
