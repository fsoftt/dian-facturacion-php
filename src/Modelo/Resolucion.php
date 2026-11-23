<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

/** Resolución de numeración de facturación autorizada por la DIAN. */
final class Resolucion
{
    public function __construct(
        public readonly string $numero,
        public readonly string $prefijo,
        public readonly int $desde,
        public readonly int $hasta,
        public readonly \DateTimeImmutable $vigenteDesde,
        public readonly \DateTimeImmutable $vigenteHasta,
        /** Clave técnica del rango; se usa para calcular el CUFE. */
        public readonly string $claveTecnica,
    ) {
    }
}
