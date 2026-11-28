<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use AIArmada\CommerceSupport\Support\MoneyFormatter;
use DateTimeInterface;

/**
 * Rate quote data from a carrier.
 */
final readonly class RateQuoteData
{
    public function __construct(
        public string $carrier,
        public string $service,
        public int $rate, // in cents
        public string $currency,
        public int $estimatedDays,
        public ?string $estimatedDeliveryDate = null,
        public ?string $serviceDescription = null,
        public ?array $restrictions = null,
        public bool $calculatedLocally = false,
        public ?string $quoteId = null,
        public ?DateTimeInterface $expiresAt = null,
        public ?string $note = null,
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
        return MoneyFormatter::formatMinorWithCode($this->rate, $this->currency);
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_replace(get_object_vars($this), ['expiresAt' => $this->expiresAt?->format(DATE_ATOM)]);
    }
}
