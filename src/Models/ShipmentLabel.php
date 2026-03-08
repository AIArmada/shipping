<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $shipment_id
 * @property string $format
 * @property string|null $size
 * @property string|null $url
 * @property string|null $content
 * @property Carbon $generated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Shipment $shipment
 */
class ShipmentLabel extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'shipment_id',
        'format',
        'size',
        'url',
        'content',
        'generated_at',
    ];

    public function getTable(): string
    {
        return config('shipping.database.tables.shipment_labels', 'shipment_labels');
    }

    /**
     * @return BelongsTo<Shipment, ShipmentLabel>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

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

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }
}
