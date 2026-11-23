<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

/** Factura afectada por una nota crédito o débito y el motivo de la corrección. */
final class Referencia
{
    /**
     * @param string $numero   Número de la factura afectada, con prefijo.
     * @param string $concepto Código del concepto de corrección. Nota crédito: 1 devolución parcial,
     *                         2 anulación, 3 rebaja o descuento, 4 ajuste de precio, 5 otros.
     *                         Nota débito: 1 intereses, 2 gastos por cobrar, 3 cambio del valor, 4 otros.
     */
    public function __construct(
        public readonly string $numero,
        public readonly string $cufe,
        public readonly \DateTimeImmutable $fecha,
        public readonly string $concepto,
        public readonly string $descripcion,
    ) {
    }
}
