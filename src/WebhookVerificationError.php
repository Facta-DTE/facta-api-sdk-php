<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * Thrown by {@see Webhook::constructEvent()} when a delivery cannot be trusted. Branch on
 * {@see WebhookVerificationError::getReason()}, never on the message.
 */
final class WebhookVerificationError extends \UnexpectedValueException
{
    /** @var string */
    private $reason;

    public function __construct(string $reason)
    {
        parent::__construct('Webhook refused: ' . $reason);
        $this->reason = $reason;
    }

    /** One of `malformed_header`, `stale_timestamp`, `bad_signature`, `malformed_body`, `replayed`. */
    public function getReason(): string
    {
        return $this->reason;
    }
}
