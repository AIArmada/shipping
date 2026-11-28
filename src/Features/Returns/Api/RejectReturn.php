<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Api;

interface RejectReturn
{
    public function execute(string $authorizationId, string $reason, ?string $actorId = null): string;
}
