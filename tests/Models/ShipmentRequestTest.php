<?php

namespace NexCargo\Tests\Models;

use NexCargo\Models\ShipmentRequest;
use NexCargo\Models\Address;
use NexCargo\Models\Dimensions;
use NexCargo\Models\Parcel;
use NexCargo\Models\PalletOptions;
use NexCargo\Tests\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShipmentRequestTest extends TestCase
{
  /** @return array<string, array{0: string}> */
  public static function requiredFieldsProvider(): array
  {
    return [
      'carrier' => ['carrier'],
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
    $this->expectExceptionMessage('ShipmentRequest requires ' . $field . '.');

    $data = Fixtures::shipmentData();
    unset($data[$field]);
    new ShipmentRequest($data);
  }

  public function testToArrayReturnsNormalizedData(): void
  {
    $request = new ShipmentRequest(Fixtures::shipmentData());
    $result = $request->toArray();

    $this->assertSame('auto', $result['carrier']);
    $this->assertSame('2026-01-15', $result['pickup_date']);
    $this->assertIsArray($result['pickup']);
    $this->assertIsArray($result['packages']);
  }

  public function testToArrayReturnsFullPayloadWithOptionalFieldsAndNestedModels(): void
  {
    $data = Fixtures::shipmentData();
    $data['reference'] = 'ORDER-123';
    $data['content_value'] = 0;
    $data['content_description'] = 'Spare parts';
    $data['services'] = ['notification' => true];
    $data['pickup'] = new Address([
      ...Fixtures::addressData(),
      'name2' => 'Goods-in',
      'email' => 'pickup@example.com',
      'notes' => 'Use side entrance',
      'contact_person' => 'Pickup Contact',
    ]);
    $data['delivery'] = new Address([
      ...Fixtures::addressData(),
      'name2' => 'Receiving',
      'email' => 'delivery@example.com',
      'notes' => 'Call on arrival',
      'contact_person' => 'Delivery Contact',
    ]);
    $data['packages'] = [new Parcel([
      'weight' => 10.0,
      'quantity' => 2,
      'type' => 'PA',
      'description' => 'Spare parts',
      'dimensions' => new Dimensions(['length' => 50, 'width' => 30, 'height' => 20]),
    ])];

    $result = (new ShipmentRequest($data))->toArray();

    $expected = Fixtures::shipmentData();
    $expected['reference'] = 'ORDER-123';
    $expected['content_value'] = 0;
    $expected['content_description'] = 'Spare parts';
    $expected['services'] = ['notification' => true];
    $expected['pickup'] = [
      'name' => 'Test GmbH',
      'name2' => 'Goods-in',
      'street' => 'Teststraße',
      'house_number' => '1',
      'zipcode' => '50667',
      'city' => 'Köln',
      'country' => 'DE',
      'phone' => '+49 123 4567890',
      'email' => 'pickup@example.com',
      'notes' => 'Use side entrance',
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
      'notes' => 'Call on arrival',
      'contact_person' => 'Delivery Contact',
    ];
    $expected['packages'] = [[
      'weight' => 10.0,
      'quantity' => 2,
      'type' => 'PA',
      'description' => 'Spare parts',
      'length' => 50.0,
      'width' => 30.0,
      'height' => 20.0,
    ]];

    $this->assertSame($expected, $result);
  }

  public function testToArrayKeepsPayloadWithoutNewOptionalFieldsUnchanged(): void
  {
    $this->assertSame(Fixtures::shipmentData(), (new ShipmentRequest(Fixtures::shipmentData()))->toArray());
  }

  public function testToArrayNormalizesPalletOptions(): void
  {
    $data = Fixtures::shipmentData();
    $data['pallet'] = new PalletOptions([
      'product' => 'PA',
      'private_recipient' => false,
      'insurance_value' => 0,
      'services' => ['tail_lift'],
    ]);

    $result = (new ShipmentRequest($data))->toArray();

    $this->assertSame([
      'product' => 'PA',
      'private_recipient' => false,
      'services' => ['tail_lift'],
      'insurance_value' => 0.0,
    ], $result['pallet']);
  }

  public function testToArrayOmitsNullOptionalFieldsAndKeepsEmptyServices(): void
  {
    $data = Fixtures::shipmentData() + [
      'reference' => null,
      'content_value' => null,
      'content_description' => null,
      'services' => [],
    ];

    $result = (new ShipmentRequest($data))->toArray();

    $this->assertNull($result['reference']);
    $this->assertNull($result['content_value']);
    $this->assertNull($result['content_description']);
    $this->assertSame([], $result['services']);
  }

  public function testNormalizesNestedSelfInstance(): void
  {
    $inner = new ShipmentRequest(Fixtures::shipmentData());
    $data = Fixtures::shipmentData();
    $data['related'] = $inner;

    $result = (new ShipmentRequest($data))->toArray();

    $this->assertIsArray($result['related']);
    $this->assertSame('auto', $result['related']['carrier']);
  }

  public function testNormalizesArrayObject(): void
  {
    $data = Fixtures::shipmentData();
    $data['metadata'] = new \ArrayObject(['source' => 'test']);

    $result = (new ShipmentRequest($data))->toArray();

    $this->assertSame(['source' => 'test'], $result['metadata']);
  }

  public function testNormalizesGenericObjectWithToArrayMethod(): void
  {
    $data = Fixtures::shipmentData();
    $data['pickup'] = new Address(Fixtures::addressData());

    $result = (new ShipmentRequest($data))->toArray();

    $this->assertSame(Fixtures::addressData(), $result['pickup']);
  }
}
