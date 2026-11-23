<?php

declare(strict_types=1);

namespace FacturacionDian\Ws;

use FacturacionDian\Ambiente;
use FacturacionDian\Firma\Certificado;

/** Operaciones del servicio web WcfDianCustomerServices. */
final class ClienteDian
{
    private readonly SobreSoap $sobre;
    private readonly Transporte $transporte;

    public function __construct(
        private readonly Ambiente $ambiente,
        Certificado $certificado,
        ?Transporte $transporte = null,
    ) {
        $this->sobre = new SobreSoap($certificado);
        $this->transporte = $transporte ?? new TransporteCurl();
    }

    /** Envío síncrono de un documento (producción y pruebas posteriores a la habilitación). */
    public function sendBillSync(string $nombreZip, string $zip): RespuestaDian
    {
        return $this->llamar('SendBillSync', ['fileName' => $nombreZip, 'contentFile' => base64_encode($zip)]);
    }

    /** Envío asíncrono; devuelve un ZipKey que se consulta con getStatusZip(). */
    public function sendBillAsync(string $nombreZip, string $zip): RespuestaDian
    {
        return $this->llamar('SendBillAsync', ['fileName' => $nombreZip, 'contentFile' => base64_encode($zip)]);
    }

    /** Envío al set de pruebas del proceso de habilitación. */
    public function sendTestSetAsync(string $nombreZip, string $zip, string $testSetId): RespuestaDian
    {
        return $this->llamar('SendTestSetAsync', [
            'fileName' => $nombreZip,
            'contentFile' => base64_encode($zip),
            'testSetId' => $testSetId,
        ]);
    }

    /** Estado de un documento por su CUFE/CUDE. */
    public function getStatus(string $trackId): RespuestaDian
    {
        return $this->llamar('GetStatus', ['trackId' => $trackId]);
    }

    /** Estado de un lote por el ZipKey devuelto en un envío asíncrono. */
    public function getStatusZip(string $zipKey): RespuestaDian
    {
        return $this->llamar('GetStatusZip', ['trackId' => $zipKey]);
    }

    /** Rangos de numeración autorizados, con su clave técnica. */
    public function getNumberingRange(string $nitFacturador, string $nitProveedor, string $softwareId): RespuestaDian
    {
        return $this->llamar('GetNumberingRange', [
            'accountCode' => $nitFacturador,
            'accountCodeT' => $nitProveedor,
            'softwareCode' => $softwareId,
        ]);
    }

    /** XML de un documento ya validado, por su CUFE/CUDE. */
    public function getXmlByDocumentKey(string $trackId): RespuestaDian
    {
        return $this->llamar('GetXmlByDocumentKey', ['trackId' => $trackId]);
    }

    /** @param array<string, string> $parametros */
    private function llamar(string $operacion, array $parametros): RespuestaDian
    {
        $url = $this->ambiente->urlServicio();
        $sobre = $this->sobre->construir($url, $operacion, $parametros);

        return new RespuestaDian($this->transporte->enviar($url, SobreSoap::accion($operacion), $sobre));
    }
}
