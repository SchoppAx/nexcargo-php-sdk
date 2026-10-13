<?php

namespace NexCargo\Tests;

use DateTimeImmutable;

final class Fixtures
{
  /** @return array<string, string> */
  public static function addressData(): array
  {
    return [
      'name' => 'Test GmbH',
      'street' => 'Teststraße',
      'house_number' => '1',
      'zipcode' => '50667',
      'city' => 'Köln',
      'country' => 'DE',
      'phone' => '+49 123 4567890',
    ];
  }

  /** @return array<string, mixed> */
  public static function packageData(): array
  {
    return ['weight' => 10.0, 'length' => 50, 'width' => 30, 'height' => 20];
  }

  /** @return array<string, mixed> */
  public static function shipmentData(bool $useDynamicDates = false): array
  {
    $today = $useDynamicDates ? new DateTimeImmutable('today') : new DateTimeImmutable('2026-01-14');

    $pickupDate = self::nextWorkingDay($today);
    $deliveryDate = self::nextWorkingDay($pickupDate);

    return [
      'carrier' => 'auto',
      'pickup_date' => $pickupDate->format('Y-m-d'),
      'pickup_time_from' => '09:00',
      'pickup_time_to' => '12:00',
      'delivery_date' => $deliveryDate->format('Y-m-d'),
      'delivery_time_from' => '08:00',
      'delivery_time_to' => '18:00',
      'pickup' => self::addressData(),
      'delivery' => self::addressData(),
      'packages' => [self::packageData()],
    ];
  }

  /** @return array<string, mixed> */
  public static function quoteData(bool $useDynamicDates = false): array
  {
    $data = self::shipmentData($useDynamicDates);
    unset($data['carrier']);
    return $data;
  }

  private static function nextWorkingDay(
    DateTimeImmutable $date
  ): DateTimeImmutable {
    do {
      $date = $date->modify('+1 day');
    } while ((int) $date->format('N') > 5);

    return $date;
  }
}
