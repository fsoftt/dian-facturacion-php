<?php

declare(strict_types=1);

namespace FacturacionDian\Ws;

/** Respuesta de una operación del servicio web de la DIAN. */
final class RespuestaDian
{
    private readonly \DOMXPath $xpath;

    public function __construct(public readonly string $xml)
    {
        $doc = new \DOMDocument();
        if ($xml === '' || !@$doc->loadXML($xml)) {
            throw new ErrorDian('La DIAN devolvió una respuesta que no es XML: ' . substr($xml, 0, 300));
        }
        $this->xpath = new \DOMXPath($doc);

        $fault = $this->texto('//*[local-name()="Fault"]//*[local-name()="Text"]');
        if ($fault !== null) {
            throw new ErrorDian("SOAP Fault de la DIAN: {$fault}");
        }
    }

    /** Resultado de la validación; null si la operación no lo informa (envíos asíncronos). */
    public function esValido(): ?bool
    {
        $valor = $this->texto('//*[local-name()="IsValid"]');

        return $valor === null ? null : $valor === 'true';
    }

    /** Código de estado: 00 procesado correctamente, 66 NSU no encontrado, 90 TrackId no encontrado, 99 con errores. */
    public function codigo(): ?string
    {
        return $this->texto('//*[local-name()="StatusCode"]');
    }

    public function descripcion(): ?string
    {
        return $this->texto('//*[local-name()="StatusDescription"]');
    }

    public function mensaje(): ?string
    {
        return $this->texto('//*[local-name()="StatusMessage"]');
    }

    /**
     * Reglas incumplidas ("Regla: FAD06, Rechazo: ...") y notificaciones.
     *
     * @return string[]
     */
    public function errores(): array
    {
        $errores = [];
        foreach ($this->xpath->query('//*[local-name()="ErrorMessage"]/*[local-name()="string"]') as $nodo) {
            $errores[] = trim($nodo->textContent);
        }
        foreach ($this->xpath->query('//*[local-name()="ErrorMessageList"]//*[local-name()="ProcessedMessage"]') as $nodo) {
            $errores[] = trim($nodo->textContent);
        }

        return $errores;
    }

    /** Identificador del lote devuelto por SendTestSetAsync y SendBillAsync; se consulta con consultarZip(). */
    public function zipKey(): ?string
    {
        return $this->texto('//*[local-name()="ZipKey"]');
    }

    /** CUFE/CUDE del documento procesado. */
    public function claveDocumento(): ?string
    {
        return $this->texto('//*[local-name()="XmlDocumentKey"]');
    }

    /** ApplicationResponse firmado por la DIAN (constancia de validación), ya decodificado. */
    public function applicationResponse(): ?string
    {
        $base64 = $this->texto('//*[local-name()="XmlBase64Bytes"]');

        return $base64 === null ? null : (base64_decode($base64, true) ?: null);
    }

    private function texto(string $consulta): ?string
    {
        $nodo = $this->xpath->query($consulta)->item(0);
        if ($nodo === null) {
            return null;
        }
        $texto = trim($nodo->textContent);

        return $texto === '' ? null : $texto;
    }
}
