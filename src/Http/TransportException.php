<?php

declare(strict_types=1);

namespace Facta\Api\Http;

/** The request produced no HTTP answer. `kind` tells the client what to say about it. */
class TransportException extends \RuntimeException
{
    public const KIND_TIMEOUT = 'timeout';
    public const KIND_TLS = 'tls';
    public const KIND_CONNECT = 'connect';
    public const KIND_OTHER = 'other';

    /** @var string */
    private $kind;

    public function __construct(string $kind, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->kind = $kind;
    }

    public function getKind(): string
    {
        return $this->kind;
    }
}
