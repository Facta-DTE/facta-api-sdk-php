<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * Remembers the webhook deliveries a receiver already accepted, so a replayed request is refused.
 * A real receiver should back this with something shared between workers (a database row, Redis).
 */
interface WebhookReplayStore
{
    public function has(string $deliveryId): bool;

    /** @param int $atSeconds The receiver's clock when the delivery was accepted. */
    public function add(string $deliveryId, int $atSeconds): void;
}
