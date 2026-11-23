<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

/** Línea de detalle de un documento. Los valores monetarios se redondean a 2 decimales. */
final class Linea
{
    /**
     * @param float      $precioUnitario Precio sin impuestos.
     * @param Impuesto[] $impuestos      Vacío para bienes o servicios excluidos.
     * @param string     $unidad         Código de unidad de medida (94 = unidad, KGM, HUR...).
     * @param string     $estandarCodigo Estándar del código del ítem (999 = adopción del contribuyente).
     */
    public function __construct(
        public readonly string $descripcion,
        public readonly float $cantidad,
        public readonly float $precioUnitario,
        public readonly array $impuestos = [],
        public readonly string $unidad = '94',
        public readonly ?string $codigo = null,
        public readonly string $estandarCodigo = '999',
    ) {
        if ($cantidad <= 0 || $precioUnitario < 0) {
            throw new \InvalidArgumentException('Cantidad y precio deben ser positivos.');
        }
    }

    /** Valor de la línea antes de impuestos. */
    public function subtotal(): float
    {
        return round($this->cantidad * $this->precioUnitario, 2);
    }

    public function valorImpuesto(Impuesto $impuesto): float
    {
        return round($this->subtotal() * $impuesto->porcentaje / 100, 2);
    }
}
