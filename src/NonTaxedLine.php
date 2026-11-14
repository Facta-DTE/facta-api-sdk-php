<?php

declare(strict_types=1);

namespace Facta\Api;

/**
 * A non-taxed charge or credit line (`items[]` entry with `noGravado`): «Cargos/Abonos que no afectan la
 * base imponible», Manual Funcional del Sistema de Transmisión v2.0 §XII. Its OWN line of a 01, 03, 05, 06
 * or 11 document; not a field of a sale line.
 *
 * Optional: a plain array (`['descripcion' => 'Capital', 'noGravado' => 50000, 'tipoItem' => 2]`) works
 * just as well; this value object only checks the shape you can check without the server and encodes
 * itself. It has no setter for `cantidad`, `precioUni`, `montoDescu`, `tipoVenta` or `productId`, which a
 * non-taxed line cannot carry, and no `uniMedida` (Facta writes 99; in a plain array it may be sent only
 * as 99). The server owns the rest:
 *
 *  - Facta writes the line as the Manual says (cantidad 1, `uniMedida` 99, `precioUni` 0, every sale
 *    column and tax 0) and adds `noGravado` ONLY to `resumen.totalNoGravado` and `totalPagar`; VAT,
 *    subtotal, discounts and the withholding/perception bases do not move. `pagos` must add up to the
 *    new total to pay. Read the sum in `totales.totalNoGravado`.
 *  - At least one sale line above zero is required (`400 non_taxed_requires_sale_line`); on 14 it is
 *    `400 non_taxed_not_supported`; credits that leave the total below zero are
 *    `400 non_taxed_total_negative`. None of these spends a control number.
 *  - In an export (11) every good or service sold is a sale line (taxed at 0 %); only a charge that is not
 *    a sale, such as a reimbursement, goes as `noGravado`.
 *
 * {@see Facta::issue()} and the other issuing calls check these lines before sending (see
 * {@see \Facta\Api\Support\NonTaxedLines}). `GET /v1/status` announces support with
 * `capabilities.noGravado`.
 */
final class NonTaxedLine implements \JsonSerializable
{
    /** `tipoItem` 1: goods. */
    public const BIEN = 1;
    /** `tipoItem` 2: services. */
    public const SERVICIO = 2;
    /** `tipoItem` 3: both. */
    public const AMBOS = 3;

    /** @var string */
    private $descripcion;
    /** @var int|float */
    private $noGravado;
    /** @var int */
    private $tipoItem;
    /** @var string|null */
    private $codigo;
    /** @var string|null */
    private $numeroDocumento;

    /**
     * @param string      $descripcion     What the charge or credit is («Capital», «Reembolso de courier»).
     * @param mixed       $noGravado       An int or float: positive = charge, negative = credit; never 0, below 1e11 in absolute value, at most 8 decimals.
     * @param int         $tipoItem        Required, never defaulted: 1 bien, 2 servicio, 3 ambos.
     * @param string|null $codigo          Your own code for the charge.
     * @param string|null $numeroDocumento 05/06: the corrected document this line belongs to, when the note corrects several.
     * @throws \InvalidArgumentException When a value cannot possibly be accepted.
     */
    public function __construct(string $descripcion, $noGravado, int $tipoItem, ?string $codigo = null, ?string $numeroDocumento = null)
    {
        if (trim($descripcion) === '') {
            throw new \InvalidArgumentException('NonTaxedLine: descripcion is required.');
        }
        if (!\Facta\Api\Support\NonTaxedLines::validAmount($noGravado)) {
            throw new \InvalidArgumentException('NonTaxedLine: noGravado must be a non-zero number below 1e11 in absolute value with at most 8 decimals.');
        }
        if ($tipoItem !== self::BIEN && $tipoItem !== self::SERVICIO && $tipoItem !== self::AMBOS) {
            throw new \InvalidArgumentException('NonTaxedLine: tipoItem must be 1 (bien), 2 (servicio) or 3 (ambos).');
        }
        $this->descripcion = $descripcion;
        $this->noGravado = $noGravado;
        $this->tipoItem = $tipoItem;
        $this->codigo = $codigo;
        $this->numeroDocumento = $numeroDocumento;
    }

    public function getDescripcion(): string
    {
        return $this->descripcion;
    }

    /** @return int|float */
    public function getNoGravado()
    {
        return $this->noGravado;
    }

    public function getTipoItem(): int
    {
        return $this->tipoItem;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function getNumeroDocumento(): ?string
    {
        return $this->numeroDocumento;
    }

    /** @return array{descripcion: string, noGravado: int|float, tipoItem: int, codigo?: string, numeroDocumento?: string} */
    public function toArray(): array
    {
        $line = ['descripcion' => $this->descripcion, 'noGravado' => $this->noGravado, 'tipoItem' => $this->tipoItem];
        if ($this->codigo !== null) {
            $line['codigo'] = $this->codigo;
        }
        if ($this->numeroDocumento !== null) {
            $line['numeroDocumento'] = $this->numeroDocumento;
        }
        return $line;
    }

    /** @return array{descripcion: string, noGravado: int|float, tipoItem: int, codigo?: string, numeroDocumento?: string} */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
