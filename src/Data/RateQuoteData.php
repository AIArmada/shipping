<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use DateTimeInterface;
use Spatie\LaravelData\Data;

/**
 * Rate quote data from a carrier.
 */
class RateQuoteData extends Data
{
    public function __construct(
        public readonly string $carrier,
        public readonly string $service,
        public readonly int $rate, // in cents
        public readonly string $currency,
        public readonly int $estimatedDays,
        public readonly ?string $estimatedDeliveryDate = null,
        public readonly ?string $serviceDescription = null,
        public readonly ?array $restrictions = null,
        public readonly bool $calculatedLocally = false,
        public readonly ?string $quoteId = null,
        public readonly ?DateTimeInterface $expiresAt = null,
        public readonly ?string $note = null,
    ) {}

    public function withRate(int $rate): self
    {
        return new self(
            carrier: $this->carrier,
            service: $this->service,
            rate: $rate,
            currency: $this->currency,
            estimatedDays: $this->estimatedDays,
            estimatedDeliveryDate: $this->estimatedDeliveryDate,
            serviceDescription: $this->serviceDescription,
            restrictions: $this->restrictions,
            calculatedLocally: $this->calculatedLocally,
            quoteId: $this->quoteId,
            expiresAt: $this->expiresAt,
            note: $this->note,
        );
    }

    public function withNote(string $note): self
    {
        return new self(
            carrier: $this->carrier,
            service: $this->service,
            rate: $this->rate,
            currency: $this->currency,
            estimatedDays: $this->estimatedDays,
            estimatedDeliveryDate: $this->estimatedDeliveryDate,
            serviceDescription: $this->serviceDescription,
            restrictions: $this->restrictions,
            calculatedLocally: $this->calculatedLocally,
            quoteId: $this->quoteId,
            expiresAt: $this->expiresAt,
            note: $note,
        );
    }

    public function getFormattedRate(): string
    {
        return number_format($this->rate / 100, 2) . ' ' . $this->currency;
    }

    public function getDeliveryEstimate(): string
    {
        if ($this->estimatedDeliveryDate) {
            return $this->estimatedDeliveryDate;
        }

        $days = $this->estimatedDays;

        return $days === 1 ? '1 business day' : "{$days} business days";
    }

    public function isFree(): bool
    {
        return $this->rate === 0;
    }

    public function getIdentifier(): string
    {
        return "{$this->carrier}:{$this->service}";
    }
}
