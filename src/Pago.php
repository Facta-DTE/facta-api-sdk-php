<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * One row of `pagos[]`: a payment method and the amount paid with it.
 *
 * `pagos` is optional and an array of plain arrays (`['codigo' => '01', 'montoPago' => 40]`) works just
 * as well; this value object only checks the shape you can check without the server and encodes itself.
 * The server owns the rules: `codigo` must be a code of the Ministry's CAT-017, and the rows must add up
 * to the total to pay to the cent (`422 validation_failed`, `details.field`, `details.supported`,
 * `details.totalPagar`, `details.sumaPagos`). Valid in 01, 03, 11 and 14; not with `formaPago`, not in
 * 05/06, not with `condicionOperacion` 2.
 */
final class Pago implements \JsonSerializable
{
    /** @var string */
    private $codigo;
    /** @var int|float */
    private $montoPago;
    /** @var string|null */
    private $referencia;

    /**
     * @param string         $codigo     Payment-method code (CAT-017), e.g. "01" cash, "02" debit card.
     * @param mixed          $montoPago  An int or float: paid with this method, greater than zero, at most two decimals.
     * @param string|null    $referencia Voucher, cheque number, etc. (at most 50 characters).
     * @throws \InvalidArgumentException When a value cannot possibly be accepted.
     */
    public function __construct(string $codigo, $montoPago, ?string $referencia = null)
    {
        if ($codigo === '') {
            throw new \InvalidArgumentException('Pago: codigo is required.');
        }
        if (!(is_int($montoPago) || is_float($montoPago)) || !is_finite((float) $montoPago) || $montoPago <= 0) {
            throw new \InvalidArgumentException('Pago: montoPago must be a number greater than zero.');
        }
        if ($referencia !== null && (int) preg_match_all('/./us', $referencia) > 50) {
            throw new \InvalidArgumentException('Pago: referencia has at most 50 characters.');
        }
        $this->codigo = $codigo;
        $this->montoPago = $montoPago;
        $this->referencia = $referencia;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    /** @return int|float */
    public function getMontoPago()
    {
        return $this->montoPago;
    }

    public function getReferencia(): ?string
    {
        return $this->referencia;
    }

    /** @return array{codigo: string, montoPago: int|float, referencia?: string} */
    public function toArray(): array
    {
        $row = ['codigo' => $this->codigo, 'montoPago' => $this->montoPago];
        if ($this->referencia !== null) {
            $row['referencia'] = $this->referencia;
        }
        return $row;
    }

    /** @return array{codigo: string, montoPago: int|float, referencia?: string} */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
