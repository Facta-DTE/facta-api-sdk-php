<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * Values of `items[].tipoVenta`: how a line treats VAT. You choose it; Facta never deduces it.
 *
 * Valid in 01, 03, 05 and 06 (in a note use the class the corrected line had). In 11 and 14 the server
 * refuses the field with `422 validation_failed`, even when it says `gravada`.
 */
final class SaleType
{
    /** The default: the line pays 13 % VAT. */
    public const GRAVADA = 'gravada';
    /** Exempt by law. */
    public const EXENTA = 'exenta';
    /** Outside the VAT taxable event. */
    public const NO_SUJETA = 'no_sujeta';

    private function __construct()
    {
    }
}
