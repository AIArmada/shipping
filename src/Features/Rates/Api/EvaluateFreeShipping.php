<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Rates\Api;

interface EvaluateFreeShipping
{
    /** @param array<string, mixed> $context */
    public function evaluate(int | object $subtotal, array $context = []): ?FreeShippingResult;
}
