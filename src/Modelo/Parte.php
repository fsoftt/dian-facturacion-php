<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

/** Emisor (facturador) o adquirente de un documento. */
final class Parte
{
    public const TIPO_NIT = '31';
    public const TIPO_CEDULA = '13';

    public const PERSONA_JURIDICA = '1';
    public const PERSONA_NATURAL = '2';

    /**
     * @param string   $identificacion    Número sin dígito de verificación.
     * @param string   $tipoIdentificacion Código DIAN (31 NIT, 13 cédula, 22 cédula extranjería, 41 pasaporte...).
     * @param string[] $responsabilidades Responsabilidades fiscales (O-13, O-15, O-23, O-47, R-99-PN).
     * @param string   $tributo           Código del tributo: 01 IVA, 04 INC, ZA IVA e INC, ZZ no aplica.
     * @param string   $regimen           listName de TaxLevelCode: 48 responsable de IVA, 49 no responsable.
     */
    public function __construct(
        public readonly string $razonSocial,
        public readonly string $identificacion,
        public readonly string $tipoIdentificacion = self::TIPO_NIT,
        public readonly string $tipoPersona = self::PERSONA_JURIDICA,
        public readonly array $responsabilidades = ['R-99-PN'],
        public readonly string $tributo = '01',
        public readonly string $regimen = '48',
        public readonly ?Direccion $direccion = null,
        public readonly ?string $nombreComercial = null,
        public readonly ?string $email = null,
        public readonly ?string $telefono = null,
        public readonly ?string $matriculaMercantil = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9]+$/', $identificacion)) {
            throw new \InvalidArgumentException('La identificación no debe llevar puntos, guiones ni dígito de verificación.');
        }
    }

    /** Adquirente genérico "consumidor final" definido por la DIAN. */
    public static function consumidorFinal(): self
    {
        return new self(
            razonSocial: 'Consumidor final',
            identificacion: '222222222222',
            tipoIdentificacion: self::TIPO_CEDULA,
            tipoPersona: self::PERSONA_NATURAL,
            responsabilidades: ['R-99-PN'],
            tributo: 'ZZ',
            regimen: '49',
        );
    }

    /** Dígito de verificación; solo existe cuando la identificación es un NIT. */
    public function digitoVerificacion(): ?string
    {
        return $this->tipoIdentificacion === self::TIPO_NIT
            ? (string) self::calcularDv($this->identificacion)
            : null;
    }

    public function nombreTributo(): string
    {
        return match ($this->tributo) {
            '01' => 'IVA',
            '04' => 'INC',
            'ZA' => 'IVA e INC',
            default => 'No aplica',
        };
    }

    /** Algoritmo módulo 11 de la DIAN para el dígito de verificación del NIT. */
    public static function calcularDv(string $nit): int
    {
        if (!ctype_digit($nit) || strlen($nit) > 15) {
            throw new \InvalidArgumentException("NIT inválido: {$nit}");
        }
        $pesos = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
        $suma = 0;
        foreach (str_split(strrev($nit)) as $i => $digito) {
            $suma += (int) $digito * $pesos[$i];
        }
        $resto = $suma % 11;

        return $resto > 1 ? 11 - $resto : $resto;
    }
}
