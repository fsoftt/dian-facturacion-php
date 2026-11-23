<?php

declare(strict_types=1);

namespace FacturacionDian;

use FacturacionDian\Modelo\Documento;
use FacturacionDian\Modelo\Impuesto;

/** Códigos únicos y de seguridad definidos en el anexo técnico de la DIAN. */
final class Cufe
{
    /**
     * CUFE (facturas, con la clave técnica de la resolución) o CUDE (notas, con el PIN del software).
     *
     * SHA-384 de: NumFac + FecFac + HorFac + ValFac + 01 + ValIVA + 04 + ValINC + 03 + ValICA
     *             + ValTot + NitOFE + NumAdq + (ClTec | PIN) + TipoAmbiente
     */
    public static function calcular(Documento $documento, Configuracion $config): string
    {
        if ($documento->tipo->esNota()) {
            $clave = $config->softwarePin;
        } else {
            $clave = $config->resolucion?->claveTecnica
                ?? throw new \LogicException('La factura requiere una resolución con clave técnica.');
        }
        $totales = $documento->totales();

        return self::hash(
            $documento->numero(),
            $documento->fecha->format('Y-m-d'),
            $documento->fecha->format('H:i:sP'),
            $totales->subtotal,
            $totales->impuesto(Impuesto::IVA),
            $totales->impuesto(Impuesto::INC),
            $totales->impuesto(Impuesto::ICA),
            $totales->total,
            $config->emisor->identificacion,
            $documento->adquirente->identificacion,
            $clave,
            $config->ambiente->value,
        );
    }

    public static function hash(
        string $numero,
        string $fecha,
        string $hora,
        float $subtotal,
        float $iva,
        float $inc,
        float $ica,
        float $total,
        string $nitEmisor,
        string $idAdquirente,
        string $clave,
        string $ambiente,
    ): string {
        return hash('sha384', $numero . $fecha . $hora
            . Formato::monto($subtotal)
            . '01' . Formato::monto($iva)
            . '04' . Formato::monto($inc)
            . '03' . Formato::monto($ica)
            . Formato::monto($total)
            . $nitEmisor . $idAdquirente . $clave . $ambiente);
    }

    /** SoftwareSecurityCode: SHA-384(Id del software + PIN + número del documento). */
    public static function codigoSeguridadSoftware(string $softwareId, string $pin, string $numero): string
    {
        return hash('sha384', $softwareId . $pin . $numero);
    }
}
