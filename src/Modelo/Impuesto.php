<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

final class Impuesto
{
    public const IVA = '01';
    public const ICA = '03';
    public const INC = '04';

    public function __construct(
        public readonly string $codigo,
        public readonly string $nombre,
        public readonly float $porcentaje,
    ) {
    }

    public static function iva(float $porcentaje = 19.0): self
    {
        return new self(self::IVA, 'IVA', $porcentaje);
    }

    public static function inc(float $porcentaje = 8.0): self
    {
        return new self(self::INC, 'INC', $porcentaje);
    }
}
