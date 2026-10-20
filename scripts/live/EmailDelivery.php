<?php

declare(strict_types=1);

namespace Facta\Api\Live;

/**
 * E-mail delivery verdict for the staging live run. Pure: no I/O and no secrets.
 * Mirrors `scripts/live-delivery.mjs` of the TypeScript SDK.
 *
 * Running out of e-mail quota, or the mail provider being rate-limited or
 * unavailable, is a WARNING. The test invoice is already sealed, delivery never
 * changes the fiscal outcome, and a shared quota running dry is not a defect in
 * this SDK. Every other delivery failure still fails the run: a rejected
 * address or document, a 5xx from the API, a malformed answer, a message that
 * went to the wrong recipient, or a channel that never reached a final state.
 */
final class EmailDelivery
{
    /** `motivo` codes of a `fallido` e-mail channel that only warn. */
    public const LIMIT_REASONS = ['quota_exceeded', 'provider_unavailable'];

    private const CODE = '/^[a-z0-9_]{1,64}$/';
    private const NOT_FINAL = ['pendiente', 'en_proceso'];

    /**
     * @param array<string, mixed> $observed
     *        `channel` is `canales.correo` from waitForDelivery, `settled` its flag, `expectedRecipient`
     *        the inbox the run asked for, `error` anything thrown while starting or reading delivery.
     * @return array{outcome: string, code: string, state?: string, status?: int}
     */
    public static function verdict(array $observed): array
    {
        $error = $observed['error'] ?? null;
        if ($error !== null) {
            $code = 'delivery_request_failed';
            $status = [];
            if ($error instanceof \Facta\Api\FactaError) {
                if (preg_match(self::CODE, $error->getErrorCode()) === 1) {
                    $code = $error->getErrorCode();
                }
                if ($error->getStatus() > 0) {
                    $status = ['status' => $error->getStatus()];
                }
            }
            return ['outcome' => 'fail', 'code' => $code] + $status;
        }
        $channel = $observed['channel'] ?? null;
        $settled = $observed['settled'] ?? null;
        $expected = $observed['expectedRecipient'] ?? null;
        if ($channel === null) {
            return ['outcome' => 'fail', 'code' => $settled === false ? 'delivery_timeout' : 'delivery_schema_invalid'];
        }
        if (
            !is_array($channel) || !isset($channel['estado']) || !is_string($channel['estado'])
            || preg_match(self::CODE, $channel['estado']) !== 1
            || (isset($channel['motivo']) && (!is_string($channel['motivo']) || preg_match(self::CODE, $channel['motivo']) !== 1))
            || (isset($channel['destino']) && !is_string($channel['destino']))
        ) {
            return ['outcome' => 'fail', 'code' => 'delivery_schema_invalid'];
        }
        $state = $channel['estado'];
        $reason = $channel['motivo'] ?? null;
        $destination = $channel['destino'] ?? '';
        // A recipient the server reports must be the one we asked for, whatever the state.
        if ($expected !== null && $expected !== '' && $destination !== '' && !self::maskedRecipientMatches($destination, $expected)) {
            return ['outcome' => 'fail', 'code' => 'wrong_recipient', 'state' => $state];
        }
        if (in_array($state, self::NOT_FINAL, true) || $settled === false) {
            return ['outcome' => 'fail', 'code' => 'delivery_timeout', 'state' => $state];
        }
        if ($state === 'enviado') {
            if ($expected !== null && $expected !== '' && $destination === '') {
                return ['outcome' => 'fail', 'code' => 'wrong_recipient', 'state' => $state];
            }
            return ['outcome' => 'pass', 'code' => 'sent', 'state' => $state];
        }
        if ($state === 'fallido' && $reason !== null && in_array($reason, self::LIMIT_REASONS, true)) {
            return ['outcome' => 'warn', 'code' => $reason, 'state' => $state];
        }
        return ['outcome' => 'fail', 'code' => $reason ?? $state, 'state' => $state];
    }

    /**
     * The lines a warning prints: a readable one and a GitHub annotation. Codes only, never data.
     *
     * @param array{outcome: string, code: string} $verdict
     * @return string[]
     */
    public static function warningLines(array $verdict): array
    {
        $what = $verdict['code'] === 'quota_exceeded'
            ? 'the e-mail sending limit was reached (quota_exceeded)'
            : 'the mail provider was unavailable or rate-limited (' . $verdict['code'] . ')';
        $text = 'E-mail delivery was not completed: ' . $what . '. The test invoice is sealed; this does not fail the run.';
        return ['WARNING: ' . $text, '::warning title=E-mail delivery limit::' . $text];
    }

    /**
     * Report state for a verdict (the report publishes only allowlisted states).
     *
     * @param array{outcome: string, code: string} $verdict
     */
    public static function reportState(array $verdict): string
    {
        if ($verdict['outcome'] === 'pass') {
            return 'Passed (e-mail sent)';
        }
        if ($verdict['outcome'] === 'warn') {
            return $verdict['code'] === 'quota_exceeded' ? 'Warning (mail quota reached)' : 'Warning (mail provider unavailable)';
        }
        return 'Failed';
    }

    /**
     * Compares a server-masked recipient («m•••@ejemplo.com») with the address asked for. Runs of
     * `•` or `*` stand for any characters; the rest must match, ignoring case.
     */
    public static function maskedRecipientMatches(string $masked, string $address): bool
    {
        if ($masked === '' || $address === '') {
            return false;
        }
        $parts = preg_split('/[•*]+/u', $masked);
        if ($parts === false) {
            return false;
        }
        $pattern = implode('.*', array_map(static function (string $part): string {
            return preg_quote($part, '/');
        }, $parts));
        return preg_match('/^' . $pattern . '$/iu', trim($address)) === 1;
    }
}
