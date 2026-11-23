<?php

declare(strict_types=1);

namespace FacturacionDian\Ws;

final class TransporteCurl implements Transporte
{
    public function __construct(private readonly int $timeoutSegundos = 60)
    {
    }

    public function enviar(string $url, string $accion, string $sobre): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $sobre,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeoutSegundos,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/soap+xml;charset=UTF-8;action="' . $accion . '"',
                'Accept: application/soap+xml',
            ],
        ]);
        $respuesta = curl_exec($ch);
        $error = curl_error($ch);
        $estado = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respuesta === false || $respuesta === '') {
            throw new ErrorDian("Sin respuesta de la DIAN (HTTP {$estado}): {$error}");
        }

        // Los errores SOAP llegan con HTTP 500 y un Fault en el cuerpo; los interpreta RespuestaDian.
        return $respuesta;
    }
}
