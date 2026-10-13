<?php

namespace NexCargo\Models;

class PalletOptions
{
  private ?string $product = null;
  private ?bool $privateRecipient = null;
  private ?bool $avis = null;
  private ?string $fixedDate = null;
  private ?string $fixedTime = null;

  /** @var array<mixed>|null */
  private ?array $services = null;

  private ?bool $insurance = null;
  private ?float $insuranceValue = null;
  private ?bool $driverAvisPickup = null;

  /**
   * @param array<string, mixed> $data
   */
  public function __construct(array $data = [])
  {
    foreach ($data as $key => $value) {
      $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
      if (property_exists($this, $property)) {
        $this->$property = $value;
      }
    }
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return array_filter([
      'product' => $this->product,
      'private_recipient' => $this->privateRecipient,
      'avis' => $this->avis,
      'fixed_date' => $this->fixedDate,
      'fixed_time' => $this->fixedTime,
      'services' => $this->services,
      'insurance' => $this->insurance,
      'insurance_value' => $this->insuranceValue,
      'driver_avis_pickup' => $this->driverAvisPickup,
    ], static fn ($value) => $value !== null);
  }
}
