<?php

declare(strict_types=1);

namespace Facta\Api\Support;

/**
 * Pure helpers for delivery by e-mail and WhatsApp. Mirrors `src/delivery.ts`
 * of the TypeScript SDK.
 *
 * @internal
 */
final class Delivery
{
    public const GENERATION_CODE = '/^[0-9A-Fa-f-]{36}$/';

    private const NUMBER = '/^\+?[0-9][0-9 ()-]{5,22}[0-9]$/';

    /**
     * Maps the `deliver` option (English names) to the wire `entrega`.
     * Refuses what could silently send nothing.
     *
     * @param mixed $deliver
     * @return array<string, mixed>
     * @throws \InvalidArgumentException
     */
    public static function requestFor($deliver): array
    {
        if (!is_array($deliver)) {
            throw new \InvalidArgumentException('deliver must be an array.');
        }
        $entrega = [];
        if (array_key_exists('email', $deliver) && $deliver['email'] !== null) {
            $email = $deliver['email'];
            if ($email !== true && (!is_string($email) || preg_match('/^[^\s@]+@[^\s@]+$/', $email) !== 1)) {
                throw new \InvalidArgumentException('deliver.email must be true (use receptor.correo) or an e-mail address.');
            }
            $entrega['correo'] = $email;
        }
        if (array_key_exists('whatsapp', $deliver) && $deliver['whatsapp'] !== null) {
            $whatsapp = $deliver['whatsapp'];
            $number = is_array($whatsapp) ? ($whatsapp['number'] ?? null) : null;
            $consent = is_array($whatsapp) ? ($whatsapp['consent'] ?? null) : null;
            if (!is_string($number) || preg_match(self::NUMBER, trim($number)) !== 1) {
                throw new \InvalidArgumentException('deliver.whatsapp.number must be a phone number, preferably in +503... form.');
            }
            if ($consent !== true) {
                throw new \InvalidArgumentException(
                    'deliver.whatsapp.consent must be true: it attests that the receiver agreed to WhatsApp delivery.'
                );
            }
            $entrega['whatsapp'] = ['numero' => trim($number), 'consentimiento' => true];
        }
        if (!isset($entrega['correo']) && !isset($entrega['whatsapp'])) {
            throw new \InvalidArgumentException('deliver must mark at least one channel (email or whatsapp).');
        }
        return $entrega;
    }

    /** `pendiente` and `en_proceso` can still change; everything else is final (even `esperando_sello`). */
    public static function isFinalState(string $state): bool
    {
        return $state !== 'pendiente' && $state !== 'en_proceso';
    }
}
