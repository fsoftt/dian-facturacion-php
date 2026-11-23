<?php

declare(strict_types=1);

namespace FacturacionDian;

/** Documento listo para transmitir a la DIAN. */
final class DocumentoFirmado
{
    public function __construct(
        public readonly string $numero,
        /** CUFE (facturas) o CUDE (notas). */
        public readonly string $cufe,
        /** XML UBL firmado. Debe conservarse sin modificar: reformatearlo invalida la firma. */
        public readonly string $xml,
        public readonly string $nombreXml,
        public readonly string $nombreZip,
        /** Texto que debe codificarse en el QR de la representación gráfica. */
        public readonly string $textoQr,
    ) {
    }

    /** ZIP con el XML firmado, como lo espera el servicio web. */
    public function zip(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'dian');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($ruta, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el ZIP temporal.');
            }
            $zip->addFromString($this->nombreXml, $this->xml);
            $zip->close();

            return file_get_contents($ruta);
        } finally {
            @unlink($ruta);
        }
    }
}
