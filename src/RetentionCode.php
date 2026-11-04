<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * Values of `items[].codigoRetencionMH` on a retention receipt (07), Hacienda catalogue CAT-006.
 *
 * With IVA_1_PERCENT and IVA_13_PERCENT the server derives `ivaRetenido` from the code (you must not send
 * it); with OTHER you state it. The SDK computes nothing.
 */
final class RetentionCode
{
    /** Withholding of 1 % of VAT (art. 162 Codigo Tributario). */
    public const IVA_1_PERCENT = '22';
    /** Withholding of 13 %. */
    public const IVA_13_PERCENT = 'C4';
    /** Other cases, no fixed rate: `ivaRetenido` is required. */
    public const OTHER = 'C9';

    private function __construct()
    {
    }
}
