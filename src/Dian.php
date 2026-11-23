<?php

declare(strict_types=1);

namespace FacturacionDian;

use FacturacionDian\Firma\FirmadorXades;
use FacturacionDian\Modelo\Documento;
use FacturacionDian\Ws\ClienteDian;
use FacturacionDian\Ws\RespuestaDian;
use FacturacionDian\Ws\Transporte;
use FacturacionDian\Xml\GeneradorUbl;

/** Punto de entrada: genera, firma y transmite documentos electrónicos a la DIAN. */
final class Dian
{
    private readonly ClienteDian $cliente;

    public function __construct(private readonly Configuracion $config, ?Transporte $transporte = null)
    {
        $this->cliente = new ClienteDian($config->ambiente, $config->certificado, $transporte);
    }

    /**
     * Calcula el CUFE/CUDE, genera el XML UBL 2.1 y lo firma.
     *
     * @param int $secuenciaEnvio Consecutivo de archivos enviados en el año (para el nombre del archivo).
     *                            Debe ser único por año; lo lleva el sistema que integra la librería.
     */
    public function preparar(Documento $documento, int $secuenciaEnvio): DocumentoFirmado
    {
        $cufe = Cufe::calcular($documento, $this->config);
        $xml = (new GeneradorUbl())->generar($documento, $this->config, $cufe);
        $firmado = (new FirmadorXades($this->config->certificado))->firmar($xml, $documento->fecha);

        $nit = $this->config->emisor->identificacion;
        $anio = (int) $documento->fecha->format('Y');

        return new DocumentoFirmado(
            numero: $documento->numero(),
            cufe: $cufe,
            xml: $firmado,
            nombreXml: Formato::nombreXml($documento->tipo, $nit, $anio, $secuenciaEnvio),
            nombreZip: Formato::nombreArchivo('z', $nit, $anio, $secuenciaEnvio, 'zip'),
            textoQr: GeneradorUbl::textoQr($documento, $this->config, $cufe),
        );
    }

    /** Transmite el documento y devuelve la validación de la DIAN (SendBillSync). */
    public function enviar(DocumentoFirmado $documento): RespuestaDian
    {
        return $this->cliente->sendBillSync($documento->nombreZip, $documento->zip());
    }

    /**
     * Transmite el documento al set de pruebas de habilitación (SendTestSetAsync).
     * El resultado se consulta después con consultarZip() usando el zipKey devuelto.
     */
    public function enviarSetPruebas(DocumentoFirmado $documento, string $testSetId): RespuestaDian
    {
        return $this->cliente->sendTestSetAsync($documento->nombreZip, $documento->zip(), $testSetId);
    }

    /** Estado de un documento por su CUFE/CUDE. */
    public function consultar(string $cufe): RespuestaDian
    {
        return $this->cliente->getStatus($cufe);
    }

    /** Estado de un envío asíncrono por su ZipKey. */
    public function consultarZip(string $zipKey): RespuestaDian
    {
        return $this->cliente->getStatusZip($zipKey);
    }

    /** Rangos de numeración autorizados al facturador para este software. */
    public function consultarRangos(): RespuestaDian
    {
        return $this->cliente->getNumberingRange(
            $this->config->emisor->identificacion,
            $this->config->nitProveedorSoftware,
            $this->config->softwareId,
        );
    }

    public function cliente(): ClienteDian
    {
        return $this->cliente;
    }
}
