<?php

declare(strict_types=1);

namespace FacturacionDian;

use FacturacionDian\Firma\Certificado;
use FacturacionDian\Modelo\Parte;
use FacturacionDian\Modelo\Resolucion;

/**
 * Datos del facturador y de su software registrado en el portal de la DIAN.
 *
 * En la modalidad "software propio" el proveedor del software es el mismo
 * facturador, por eso el NIT del proveedor se toma del emisor salvo que se
 * indique otro.
 */
final class Configuracion
{
    public readonly string $nitProveedorSoftware;

    public function __construct(
        public readonly Ambiente $ambiente,
        public readonly Parte $emisor,
        /** Identificador del software asignado por la DIAN (UUID). */
        public readonly string $softwareId,
        /** PIN definido al registrar el software en el portal. */
        public readonly string $softwarePin,
        public readonly Certificado $certificado,
        /** Resolución de numeración para facturas (no aplica a notas). */
        public readonly ?Resolucion $resolucion = null,
        ?string $nitProveedorSoftware = null,
    ) {
        $this->nitProveedorSoftware = $nitProveedorSoftware ?? $emisor->identificacion;
    }
}
