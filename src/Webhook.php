<?php

declare(strict_types=1);

namespace Facta\Api;

use Facta\Api\Json\Json;

/**
 * Verifies a webhook delivery from Facta. A webhook is a hint to read, never the data: the body is
 *
 *     {deliveryId, event, company, recordId, revision, generation, at}
 *
 * (ids, a revision, a generation and a timestamp). Read the record with `getCustomer()` /
 * `getProduct()` or catch up with `listCatalogChanges()`.
 *
 * The `Facta-Signature` header is `t=<unix seconds>,v1=<hex HMAC-SHA256(secret, t . "." . body)>`.
 * Verify the RAW request body, byte for byte (`file_get_contents('php://input')`), never a
 * re-encoded copy. Checks, in this order: the header parses, the timestamp is within the tolerance
 * of your clock, one `v1` matches (several may be present while a secret rotates), the body is the
 * expected object, and the `deliveryId` has not been accepted before. Only a request that passes
 * every check is remembered. Answer 2xx to a repeated `deliveryId`, and order events by `revision`.
 *
 * This mirrors the reference verifier published with the API (`verifyWebhook`).
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'Facta-Signature';
    public const TOLERANCE_SECONDS = 300;

    /**
     * @param string               $secret `secreto` returned by createWebhook() / rotateWebhookSecret().
     * @param string|null          $header The `Facta-Signature` header value.
     * @param string               $body   The raw request body.
     * @param array<string, mixed> $options `nowSeconds` (int), `toleranceSeconds` (int), `store` ({@see ReplayStore}).
     * @return array<string, mixed> `['ok' => true, 'deliveryId', 'event', 'payload']`, or
     *         `['ok' => false, 'reason' => ...]` with one of `malformed_header`, `stale_timestamp`,
     *         `bad_signature`, `malformed_body`, `replayed`.
     */
    public static function verify(string $secret, ?string $header, string $body, array $options = []): array
    {
        $now = isset($options['nowSeconds']) && is_int($options['nowSeconds']) ? $options['nowSeconds'] : time();
        $tolerance = isset($options['toleranceSeconds']) && is_int($options['toleranceSeconds'])
            ? $options['toleranceSeconds']
            : self::TOLERANCE_SECONDS;
        $store = $options['store'] ?? null;
        if ($store !== null && !($store instanceof ReplayStore)) {
            throw new \InvalidArgumentException('store must implement Facta\Api\ReplayStore.');
        }

        $t = null;
        $candidates = [];
        foreach (explode(',', (string) $header) as $part) {
            $at = strpos($part, '=');
            if ($at === false || $at < 1) {
                continue;
            }
            $name = trim(substr($part, 0, $at));
            $value = trim(substr($part, $at + 1));
            if ($name === 't' && preg_match('/^[0-9]{1,12}$/', $value) === 1) {
                $t = (int) $value;
            }
            if ($name === 'v1' && preg_match('/^[0-9a-f]{64}$/', $value) === 1) {
                $candidates[] = $value;
            }
        }
        if ($t === null || $candidates === []) {
            return ['ok' => false, 'reason' => 'malformed_header'];
        }
        if (abs($now - $t) > $tolerance) {
            return ['ok' => false, 'reason' => 'stale_timestamp'];
        }
        $expected = hash_hmac('sha256', $t . '.' . $body, $secret);
        $matched = false;
        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
            }
        }
        if (!$matched) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        try {
            $payload = Json::decode($body);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'reason' => 'malformed_body'];
        }
        if (!($payload instanceof \stdClass)) {
            return ['ok' => false, 'reason' => 'malformed_body'];
        }
        $record = Json::toArray($payload);
        if (!is_array($record)) {
            return ['ok' => false, 'reason' => 'malformed_body'];
        }
        $deliveryId = $record['deliveryId'] ?? null;
        $event = $record['event'] ?? null;
        if (!is_string($deliveryId) || $deliveryId === '' || !is_string($event)) {
            return ['ok' => false, 'reason' => 'malformed_body'];
        }
        if ($store !== null) {
            if ($store->has($deliveryId)) {
                return ['ok' => false, 'reason' => 'replayed'];
            }
            $store->add($deliveryId, $now);
        }
        return ['ok' => true, 'deliveryId' => $deliveryId, 'event' => $event, 'payload' => $record];
    }

    /**
     * Like {@see Webhook::verify()}, but returns the payload and throws on any failure.
     *
     * @param array<string, mixed> $options Same as verify().
     * @return array<string, mixed> The delivery body.
     * @throws \UnexpectedValueException With the verify() reason as the message.
     */
    public static function constructEvent(string $secret, ?string $header, string $body, array $options = []): array
    {
        $result = self::verify($secret, $header, $body, $options);
        if ($result['ok'] !== true) {
            throw new \UnexpectedValueException('Webhook refused: ' . $result['reason']);
        }
        return $result['payload'];
    }

    /**
     * The `Facta-Signature` value for `$body` at unix time `$t`. For tests of your own receiver.
     */
    public static function sign(string $secret, string $body, ?int $t = null): string
    {
        $t = $t ?? time();
        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }
}
