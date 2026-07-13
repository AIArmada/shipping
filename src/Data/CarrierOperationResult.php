<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

final class CarrierOperationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly bool $retryable = false,
        public readonly bool $alreadyApplied = false,
        public readonly ?string $trackingNumber = null,
        public readonly ?string $carrierReference = null,
        public readonly ?string $error = null,
        public readonly ?string $outcomeType = null,
    ) {}

    public static function succeeded(?string $trackingNumber = null, ?string $carrierReference = null): self
    {
        return new self(
            success: true,
            trackingNumber: $trackingNumber,
            carrierReference: $carrierReference,
            outcomeType: 'succeeded',
        );
    }

    public static function failed(?string $error = null, bool $retryable = false): self
    {
        return new self(
            success: false,
            retryable: $retryable,
            error: $error,
            outcomeType: $retryable ? 'retryable_error' : 'terminal_error',
        );
    }

    public static function unknown(?string $error = null): self
    {
        return new self(
            success: false,
            retryable: true,
            error: $error,
            outcomeType: 'unknown',
        );
    }

    public static function alreadyApplied(): self
    {
        return new self(
            success: true,
            alreadyApplied: true,
            outcomeType: 'already_applied',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'retryable' => $this->retryable,
            'already_applied' => $this->alreadyApplied,
            'tracking_number' => $this->trackingNumber,
            'carrier_reference' => $this->carrierReference,
            'error' => $this->error,
            'outcome_type' => $this->outcomeType,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: (bool) ($data['success'] ?? false),
            retryable: (bool) ($data['retryable'] ?? false),
            alreadyApplied: (bool) ($data['already_applied'] ?? false),
            trackingNumber: $data['tracking_number'] ?? null,
            carrierReference: $data['carrier_reference'] ?? null,
            error: $data['error'] ?? null,
            outcomeType: $data['outcome_type'] ?? null,
        );
    }
}
