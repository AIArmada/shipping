<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class LabelController extends Controller
{
    public function show(Request $request, string $trackingNumber): Response
    {
        $cacheKey = "shipping_label:{$trackingNumber}";
        $labelData = Cache::get($cacheKey);

        if ($labelData === null) {
            abort(404, 'Label not found or expired');
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
}
