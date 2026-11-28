<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

/**
 * Shipping label data.
 */
final readonly class LabelData
{
    public function __construct(
        public string $format, // pdf, png, zpl
        public ?string $url = null,
        public ?string $content = null, // base64 encoded
        public ?string $size = null, // a4, a6, 4x6
        public ?string $trackingNumber = null,
    ) {}

    public function hasUrl(): bool
    {
        return $this->url !== null;
    }

    public function hasContent(): bool
    {
        return $this->content !== null;
    }

    public function getDecodedContent(): ?string
    {
        if ($this->content === null) {
            return null;
        }

        $decoded = base64_decode($this->content, true);

        return $decoded === false ? null : $decoded;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
