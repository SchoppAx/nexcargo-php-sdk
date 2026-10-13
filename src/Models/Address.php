<?php

namespace NexCargo\Models;

class Address
{
  private ?string $name = null;
  private ?string $name2 = null;
  private ?string $street = null;
  private ?string $houseNumber = null;
  private ?string $zipcode = null;
  private ?string $city = null;
  private ?string $country = null;
  private ?string $phone = null;
  private ?string $email = null;
  private ?string $notes = null;
  private ?string $contactPerson = null;

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
      'name' => $this->name,
      'name2' => $this->name2,
      'street' => $this->street,
      'house_number' => $this->houseNumber,
      'zipcode' => $this->zipcode,
      'city' => $this->city,
      'country' => $this->country,
      'phone' => $this->phone,
      'email' => $this->email,
      'notes' => $this->notes,
      'contact_person' => $this->contactPerson,
    ], static fn ($value) => $value !== null);
  }
}
