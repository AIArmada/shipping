<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Contracts;

use AIArmada\Shipping\Data\AddressData;

/**
 * Result of an address validation operation.
 */
class AddressValidationResult
{
    /**
     * @param  array<string>  $warnings
     * @param  array<string>  $errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?AddressData $correctedAddress = null,
        public readonly array $warnings = [],
        public readonly array $errors = [],
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
