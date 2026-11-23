<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

final class Direccion
{
    /**
     * @param string $codigoMunicipio    Código DANE del municipio, 5 dígitos (11001 Bogotá).
     * @param string $codigoDepartamento Código DANE del departamento, 2 dígitos (11 Bogotá D.C.).
     */
    public function __construct(
        public readonly string $codigoMunicipio,
        public readonly string $ciudad,
        public readonly string $codigoDepartamento,
        public readonly string $departamento,
        public readonly string $linea,
        public readonly ?string $codigoPostal = null,
        public readonly string $codigoPais = 'CO',
        public readonly string $pais = 'Colombia',
    ) {
    }
}
