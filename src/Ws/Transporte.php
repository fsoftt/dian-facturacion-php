<?php

declare(strict_types=1);

namespace FacturacionDian\Ws;

/** Envío HTTP del sobre SOAP. Permite sustituir cURL en pruebas o por otro cliente HTTP. */
interface Transporte
{
    /**
     * @return string Cuerpo de la respuesta.
     *
     * @throws ErrorDian si no hay respuesta del servicio.
     */
    public function enviar(string $url, string $accion, string $sobre): string;
}
