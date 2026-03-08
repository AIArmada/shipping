<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Data;

use Spatie\LaravelData\Data;

/**
 * Shipping label data.
 */
class LabelData extends Data
{
    public function __construct(
        public readonly string $format, // pdf, png, zpl
        public readonly ?string $url = null,
        public readonly ?string $content = null, // base64 encoded
        public readonly ?string $size = null, // a4, a6, 4x6
        public readonly ?string $trackingNumber = null,
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
}
