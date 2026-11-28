<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Data\AddressData;

/**
 * Result of an address validation operation.
 */
final readonly class AddressValidationResult
{
    /**
     * @param  array<string>  $warnings
     * @param  array<string>  $errors
     */
    public function __construct(
        public bool $valid,
        public ?AddressData $correctedAddress = null,
        public array $warnings = [],
        public array $errors = [],
    ) {}

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function hasCorrectedAddress(): bool
    {
        return $this->correctedAddress !== null;
    }

    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}
