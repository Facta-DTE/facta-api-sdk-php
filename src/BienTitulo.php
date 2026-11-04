<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * Values of `receptor.bienTitulo` on a remission note (04): the title under which the goods travel
 * (Hacienda catalogue CAT-025). It is what the document legally states, so you always choose it:
 * there is no default and a stored customer does not supply it. Valid on 04 only.
 */
final class BienTitulo
{
    public const DEPOSITO = '01';
    public const PROPIEDAD = '02';
    public const CONSIGNACION = '03';
    public const TRASLADO = '04';
    public const OTROS = '05';

    private function __construct()
    {
    }
}
