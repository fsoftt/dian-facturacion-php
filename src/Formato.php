<?php

declare(strict_types=1);

namespace FacturacionDian;

use FacturacionDian\Modelo\TipoDocumento;

final class Formato
{
    /** Valor monetario con punto decimal y dos decimales, como lo exige la DIAN. */
    public static function monto(float $valor): string
    {
        return number_format($valor, 2, '.', '');
    }

    /**
     * Nombre de archivo según el anexo técnico: tipo + NIT (10) + código PT (000 para
     * software propio) + año (2) + consecutivo de envío del año en hexadecimal (8).
     *
     * @param string $prefijo fv, nc, nd, o z para el ZIP.
     */
    public static function nombreArchivo(string $prefijo, string $nit, int $anio, int $secuencia, string $extension): string
    {
        return sprintf('%s%s000%02d%08x.%s', $prefijo, str_pad($nit, 10, '0', STR_PAD_LEFT), $anio % 100, $secuencia, $extension);
    }

    public static function nombreXml(TipoDocumento $tipo, string $nit, int $anio, int $secuencia): string
    {
        return self::nombreArchivo($tipo->prefijoArchivo(), $nit, $anio, $secuencia, 'xml');
    }
}
