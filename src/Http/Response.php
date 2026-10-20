<?php

declare(strict_types=1);

namespace Facta\Api\Http;

/** A received HTTP answer. Header names are lower-case. */
final class Response
{
    /** @var int */
    private $status;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = $value;
        }
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $key = strtolower($name);
        return $this->headers[$key] ?? null;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
