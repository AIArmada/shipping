<?php

declare(strict_types=1);

namespace AIArmada\Shipping\Features\Returns\Api;

interface ApproveReturn
{
    public function execute(string $authorizationId, ?string $notes = null, ?string $actorId = null): string;
}
