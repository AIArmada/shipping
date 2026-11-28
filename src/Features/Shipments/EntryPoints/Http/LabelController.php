<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Shipments\EntryPoints\Http;

use AIArmada\CommerceSupport\Features\SignedDownloads\Api\SignedDownloadApi;
use AIArmada\CommerceSupport\Support\EloquentRecordReference;
use AIArmada\CommerceSupport\Support\FileSignature;
use AIArmada\CommerceSupport\Support\OwnerContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class LabelController
{
    public function show(Request $request, string $trackingNumber, SignedDownloadApi $downloads): Response
    {
        $data = $downloads->payloadFromToken($request->query(SignedDownloadApi::TOKEN_QUERY_PARAM), 'shipping_label');

        if ($data === null || ! is_string($data['content'] ?? null) || $data['content'] === '') {
            abort(404, 'Label not found or expired');
        }

        if (! $downloads->isAuthorizedPayload(
            payload: $data,
            resourceIdKey: 'tracking_number',
            expectedResourceId: $trackingNumber,
            owner: EloquentRecordReference::fromModel(OwnerContext::resolve()),
            userId: $request->user()?->getAuthIdentifier(),
        )) {
            abort(403, 'Unauthorized label access');
        }

        $format = $data['format'] ?? null;

        if (! in_array($format, ['pdf', 'png', 'zpl'], true)) {
            abort(404, 'Unsupported label format');
        }

        if (($format === 'pdf' && ! FileSignature::isPdf($data['content']))
            || ($format === 'png' && ! FileSignature::isPng($data['content']))) {
            abort(404, 'Invalid label content');
        }

        $base = mb_trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $trackingNumber) ?? '', '._');
        $filename = 'label-' . mb_substr($base === '' ? 'shipment' : $base, 0, 100) . '.' . $format;

        return response($data['content'])
            ->header('Content-Type', match ($format) {
                'pdf' => 'application/pdf',
                'png' => 'image/png',
                default => 'application/zpl',
            })
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
