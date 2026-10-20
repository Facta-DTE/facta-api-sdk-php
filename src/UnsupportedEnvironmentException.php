<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * Thrown at construction when this PHP installation lacks something the SDK
 * needs (no HTTP transport, or HTTPS without ext-openssl). Reported before any
 * request, never in the middle of an issuance. The message names the fix.
 */
class UnsupportedEnvironmentException extends \RuntimeException
{
}
