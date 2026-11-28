<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Api;

interface ReturnStatistics
{
    /** @return array{pendingReturns: int, approvedReturns: int} */
    public function pendingActions(): array;

    public function pendingCount(): int;
}
