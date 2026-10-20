<?php

declare(strict_types=1);

namespace Facta\Api\Live;

/**
 * A failure of the live run, with a stable machine-readable code. The message is
 * written by this script and never embeds API data, so it is safe to print.
 */
class LiveError extends \RuntimeException
{
    /** @var string */
    private $failureCode;

    public function __construct(string $failureCode, string $message)
    {
        parent::__construct($message);
        $this->failureCode = $failureCode;
    }

    public function getFailureCode(): string
    {
        return $this->failureCode;
    }
}
