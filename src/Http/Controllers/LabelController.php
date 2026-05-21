<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Http\Controllers;

use AIArmada\CommerceSupport\Support\OwnerContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class LabelController extends Controller
{
    public function show(Request $request, string $trackingNumber): Response
    {
        $token = $request->query('token');

        if (! is_string($token) || $token === '') {
            abort(404, 'Label not found or expired');
        }

        $cacheKey = "shipping_label:{$token}";
        $labelData = Cache::get($cacheKey);

        if ($labelData === null) {
            abort(404, 'Label not found or expired');
        }

        if (($labelData['tracking_number'] ?? null) !== $trackingNumber) {
            abort(403, 'Unauthorized label access');
        }

        $userId = auth()->id();

        if ($userId === null || (string) ($labelData['user_id'] ?? '') !== (string) $userId) {
            abort(403, 'Unauthorized label access');
        }

        $owner = OwnerContext::resolve();
        $ownerType = isset($labelData['owner_type']) && is_string($labelData['owner_type'])
            ? $labelData['owner_type']
            : null;
        $ownerId = $labelData['owner_id'] ?? null;

        if (! $this->matchesOwner($owner, $ownerType, $ownerId)) {
            abort(403, 'Unauthorized label access');
        }

        $content = $labelData['content'];
        $format = $labelData['format'] ?? 'pdf';
        $filename = "label-{$trackingNumber}.{$format}";

        $mimeType = match ($format) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'zpl' => 'application/zpl',
            default => 'application/octet-stream',
        };

        return response($content)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', "inline; filename=\"{$filename}\"")
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function matchesOwner(?Model $owner, ?string $expectedOwnerType, string | int | null $expectedOwnerId): bool
    {
        if ($expectedOwnerType === null && $expectedOwnerId === null) {
            return $owner === null;
        }

        if ($owner === null || $expectedOwnerType === null || $expectedOwnerId === null) {
            return false;
        }

        return $owner->getMorphClass() === $expectedOwnerType
            && (string) $owner->getKey() === (string) $expectedOwnerId;
    }
}
