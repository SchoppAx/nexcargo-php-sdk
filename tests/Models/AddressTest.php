<?php

namespace NexCargo\Tests\Models;

use NexCargo\Models\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AddressTest extends TestCase
{
  /**
   * @return array<string, array{0: string, 1: string}>
   */
  public static function addressFieldsProvider(): array
  {
    return [
      'name2' => ['name2', 'Zusatzname'],
      'phone' => ['phone', '+49 123 4567890'],
      'email' => ['email', 'kontakt@example.com'],
      'notes' => ['notes', 'Bitte beim Empfang melden'],
      'contact_person' => ['contact_person', 'Max Mustermann'],
    ];
  }

  #[DataProvider('addressFieldsProvider')]
  public function testToArrayIncludesAddressField(string $field, string $value): void
  {
    $this->assertSame([$field => $value], (new Address([$field => $value]))->toArray());
  }

  public function testToArrayOmitsUnsetAndNullFields(): void
  {
    $address = new Address([
      'name2' => null,
      'email' => null,
      'notes' => null,
      'contact_person' => null,
    ]);

    $this->assertSame([], $address->toArray());
  }

  public function testToArrayCombinesExistingAndNewFields(): void
  {
    $address = new Address([
      'name' => 'Test GmbH',
      'street' => 'Teststraße',
      'phone' => '+49 123 4567890',
      'name2' => 'Wareneingang',
      'email' => 'kontakt@example.com',
      'notes' => 'Seiteneingang benutzen',
      'contact_person' => 'Erika Musterfrau',
    ]);

    $this->assertSame([
      'name' => 'Test GmbH',
      'name2' => 'Wareneingang',
      'street' => 'Teststraße',
      'phone' => '+49 123 4567890',
      'email' => 'kontakt@example.com',
      'notes' => 'Seiteneingang benutzen',
      'contact_person' => 'Erika Musterfrau',
    ], $address->toArray());
  }

  public function testToArrayPreservesEmptyStringValues(): void
  {
    $address = new Address(['name2' => '', 'notes' => '']);

    $this->assertSame(['name2' => '', 'notes' => ''], $address->toArray());
  }
}
