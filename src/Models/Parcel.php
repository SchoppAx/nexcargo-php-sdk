<?php

namespace NexCargo\Models;

class Parcel
{
  private ?float $weight = null;
  private ?Dimensions $dimensions = null;
  private ?int $quantity = null;
  private ?string $type = null;
  private ?string $description = null;

  /**
   * @param array<string, mixed> $data
   */
  public function __construct(array $data = [])
  {
    foreach ($data as $key => $value) {
      $property = lcfirst(str_replace('_', '', ucwords($key, '_')));
      if ($property === 'dimensions' && $value instanceof Dimensions) {
        $this->dimensions = $value;
      } elseif (property_exists($this, $property)) {
        $this->$property = $value;
      }
    }
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    $data = [
      'weight' => $this->weight,
      'quantity' => $this->quantity,
      'type' => $this->type,
      'description' => $this->description,
    ];

    if ($this->dimensions !== null) {
      $data = array_merge($data, $this->dimensions->toArray());
    }

    return array_filter($data, static fn ($value) => $value !== null);
  }
}
