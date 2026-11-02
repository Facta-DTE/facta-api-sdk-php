<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * `descuentoGlobal`: the discount of the WHOLE sale, as the Facta DTE app writes it, in money or as a
 * percentage. Applied after the per-line `montoDescu`; Facta spreads it over the sale classes.
 *
 * Optional: a plain array (`['modo' => 'porcentaje', 'valor' => 10]`) works just as well; this value
 * object only checks the shape you can check without the server and encodes itself. The server owns the
 * rules:
 *
 *  - Valid in 01, 03, 11 and 14. On a credit (05) or debit (06) note it is
 *    `400 global_discount_not_supported`: their amounts come from the document they correct.
 *  - `monto` is in dollars, in the SAME VAT basis as `precioUni` for that type (VAT included on 01, net
 *    on 03 and 11). `porcentaje` is in points (10 = 10 %) and fills `resumen.porcentajeDescuento`.
 *  - `valor` is greater than zero with at most two decimals; a percentage is at most 100. A discount
 *    above the subtotal is `400 invalid_request` with `details.field` "descuentoGlobal" and
 *    `details.subtotal`. None of these refusals spends a control number.
 *  - Everything after it (VAT, `aplicarRetencionIva`, `aplicarPercepcionIva`, `aplicarReteRenta`,
 *    `totalPagar` and the sum `pagos` must reach) is computed on the discounted amounts.
 *
 * `GET /v1/status` announces support with `capabilities.descuentoGlobal`. An older server ignores the
 * field and reports it as a `campo_desconocido` warning, so check `unknownFields` of the answer.
 */
final class DescuentoGlobal implements \JsonSerializable
{
    /** A discount in money, in the VAT basis of `precioUni`. */
    public const MONTO = 'monto';
    /** A discount in percentage points (10 = 10 %). */
    public const PORCENTAJE = 'porcentaje';

    /** @var string */
    private $modo;
    /** @var int|float */
    private $valor;

    /**
     * @param string $modo  {@see DescuentoGlobal::MONTO} or {@see DescuentoGlobal::PORCENTAJE}.
     * @param mixed  $valor An int or float greater than zero; a percentage is at most 100.
     * @throws \InvalidArgumentException When a value cannot possibly be accepted.
     */
    public function __construct(string $modo, $valor)
    {
        if ($modo !== self::MONTO && $modo !== self::PORCENTAJE) {
            throw new \InvalidArgumentException('DescuentoGlobal: modo must be "monto" or "porcentaje".');
        }
        if (!(is_int($valor) || is_float($valor)) || !is_finite((float) $valor) || $valor <= 0) {
            throw new \InvalidArgumentException('DescuentoGlobal: valor must be a number greater than zero.');
        }
        if ($modo === self::PORCENTAJE && $valor > 100) {
            throw new \InvalidArgumentException('DescuentoGlobal: a percentage is at most 100.');
        }
        $this->modo = $modo;
        $this->valor = $valor;
    }

    /** @param mixed $valor Dollars, in the VAT basis of `precioUni`. */
    public static function monto($valor): self
    {
        return new self(self::MONTO, $valor);
    }

    /** @param mixed $valor Percentage points (10 = 10 %). */
    public static function porcentaje($valor): self
    {
        return new self(self::PORCENTAJE, $valor);
    }

    public function getModo(): string
    {
        return $this->modo;
    }

    /** @return int|float */
    public function getValor()
    {
        return $this->valor;
    }

    /** @return array{modo: string, valor: int|float} */
    public function toArray(): array
    {
        return ['modo' => $this->modo, 'valor' => $this->valor];
    }

    /** @return array{modo: string, valor: int|float} */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
