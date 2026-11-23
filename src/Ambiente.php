<?php

declare(strict_types=1);

namespace FacturacionDian;

/** Ambiente de destino de la DIAN (ProfileExecutionID). */
enum Ambiente: string
{
    case Produccion = '1';
    case Habilitacion = '2';

    /** URL del servicio web WcfDianCustomerServices. */
    public function urlServicio(): string
    {
        return match ($this) {
            self::Produccion => 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc',
            self::Habilitacion => 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc',
        };
    }

    /** URL de consulta pública que se incluye en el código QR. */
    public function urlConsulta(string $cufe): string
    {
        $host = $this === self::Produccion ? 'catalogo-vpfe.dian.gov.co' : 'catalogo-vpfe-hab.dian.gov.co';

        return "https://{$host}/document/searchqr?documentkey={$cufe}";
    }
}
