<?php

namespace NexCargo\Tests\Models;

use NexCargo\Models\Dimensions;
use NexCargo\Models\Parcel;
use PHPUnit\Framework\TestCase;

class ParcelTest extends TestCase
{
  public function testToArrayWithWeightOnly(): void
  {
    $parcel = new Parcel(['weight' => 15.5]);

    $this->assertSame(['weight' => 15.5], $parcel->toArray());
  }

  public function testToArrayWithDimensions(): void
  {
    $dimensions = new Dimensions(['length' => 60, 'width' => 40, 'height' => 30]);
    $parcel = new Parcel(['weight' => 15.5, 'dimensions' => $dimensions]);

    $this->assertSame([
      'weight' => 15.5,
      'length' => 60.0,
      'width' => 40.0,
      'height' => 30.0,
    ], $parcel->toArray());
  }

  public function testToArrayWithNoFieldsIsEmpty(): void
  {
    $this->assertSame([], (new Parcel())->toArray());
  }
}
