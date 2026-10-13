<?php

namespace NexCargo\Tests\Models;

use NexCargo\Models\PalletOptions;
use PHPUnit\Framework\TestCase;

class PalletOptionsTest extends TestCase
{
  public function testToArrayWithAllFields(): void
  {
    $options = new PalletOptions([
      'product' => 'PA',
      'private_recipient' => true,
      'avis' => true,
      'fixed_date' => '2026-10-15',
      'fixed_time' => '10:00',
      'services' => ['tail_lift'],
      'insurance' => true,
      'insurance_value' => 1250.0,
      'driver_avis_pickup' => true,
    ]);

    $this->assertSame([
      'product' => 'PA',
      'private_recipient' => true,
      'avis' => true,
      'fixed_date' => '2026-10-15',
      'fixed_time' => '10:00',
      'services' => ['tail_lift'],
      'insurance' => true,
      'insurance_value' => 1250.0,
      'driver_avis_pickup' => true,
    ], $options->toArray());
  }

  public function testToArrayWithSubsetOmitsUnsetFields(): void
  {
    $this->assertSame(
      ['product' => 'PA', 'services' => ['tail_lift']],
      (new PalletOptions(['product' => 'PA', 'services' => ['tail_lift']]))->toArray()
    );
  }

  public function testToArrayPreservesFalseAndZeroValues(): void
  {
    $options = new PalletOptions([
      'private_recipient' => false,
      'avis' => false,
      'insurance' => false,
      'insurance_value' => 0,
      'driver_avis_pickup' => false,
    ]);

    $this->assertSame([
      'private_recipient' => false,
      'avis' => false,
      'insurance' => false,
      'insurance_value' => 0.0,
      'driver_avis_pickup' => false,
    ], $options->toArray());
  }

  public function testToArrayOmitsNullFields(): void
  {
    $options = new PalletOptions([
      'product' => null,
      'private_recipient' => null,
      'avis' => null,
      'fixed_date' => null,
      'fixed_time' => null,
      'services' => null,
      'insurance' => null,
      'insurance_value' => null,
      'driver_avis_pickup' => null,
    ]);

    $this->assertSame([], $options->toArray());
  }
}
