<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * A {@see ReplayStore} that lives in this PHP process and forgets entries older than twice the
 * tolerance window (they could not verify any more anyway). Enough for tests and a single worker;
 * PHP-FPM workers do not share it.
 */
final class MemoryReplayStore implements ReplayStore
{
    /** @var array<string, int> */
    private $seen = [];

    public function has(string $deliveryId): bool
    {
        return isset($this->seen[$deliveryId]);
    }

    public function add(string $deliveryId, int $atSeconds): void
    {
        $this->seen[$deliveryId] = $atSeconds;
        foreach ($this->seen as $id => $when) {
            if ($atSeconds - $when > Webhook::TOLERANCE_SECONDS * 2) {
                unset($this->seen[$id]);
            }
        }
    }

    public function size(): int
    {
        return count($this->seen);
    }
}
