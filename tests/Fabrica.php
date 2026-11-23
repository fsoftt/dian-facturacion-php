<?php

declare(strict_types=1);

namespace FacturacionDian\Tests;

use FacturacionDian\Ambiente;
use FacturacionDian\Configuracion;
use FacturacionDian\Firma\Certificado;
use FacturacionDian\Modelo\Direccion;
use FacturacionDian\Modelo\Documento;
use FacturacionDian\Modelo\Impuesto;
use FacturacionDian\Modelo\Linea;
use FacturacionDian\Modelo\Parte;
use FacturacionDian\Modelo\Resolucion;
use FacturacionDian\Modelo\TipoDocumento;

/** Datos de prueba compartidos. */
final class Fabrica
{
    private static ?Certificado $certificado = null;

    /** Certificado autofirmado generado en memoria; solo sirve para pruebas locales. */
    public static function certificado(): Certificado
    {
        if (self::$certificado === null) {
            $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $dn = ['C' => 'CO', 'O' => 'Empresa de Prueba SAS', 'CN' => 'Prueba, Firma'];
            $csr = openssl_csr_new($dn, $llave, ['digest_alg' => 'sha256']);
            $x509 = openssl_csr_sign($csr, null, $llave, 30, ['digest_alg' => 'sha256'], 0x7fffffff);
            openssl_x509_export($x509, $certPem);
            openssl_pkey_export($llave, $llavePem);
            self::$certificado = Certificado::desdePem($certPem, $llavePem);
        }

        return self::$certificado;
    }

    public static function configuracion(): Configuracion
    {
        $bogota = new Direccion('11001', 'Bogotá, D.C.', '11', 'Bogotá', 'Calle 100 # 10-20', '110111');

        return new Configuracion(
            ambiente: Ambiente::Habilitacion,
            emisor: new Parte(
                razonSocial: 'Empresa de Prueba SAS',
                identificacion: '900373115',
                responsabilidades: ['O-13', 'O-15'],
                direccion: $bogota,
                email: 'facturacion@example.com',
                matriculaMercantil: '12345-67',
            ),
            softwareId: '56f2ae4e-9812-4fad-9255-08fcfcd5ccb0',
            softwarePin: '12345',
            certificado: self::certificado(),
            resolucion: new Resolucion(
                numero: '18760000001',
                prefijo: 'SETP',
                desde: 990000000,
                hasta: 995000000,
                vigenteDesde: new \DateTimeImmutable('2019-01-19'),
                vigenteHasta: new \DateTimeImmutable('2030-01-19'),
                claveTecnica: 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c',
            ),
        );
    }

    public static function factura(): Documento
    {
        return new Documento(
            tipo: TipoDocumento::Factura,
            prefijo: 'SETP',
            consecutivo: 990000001,
            fecha: new \DateTimeImmutable('2026-11-23 10:30:00', new \DateTimeZone('America/Bogota')),
            adquirente: new Parte(
                razonSocial: 'Cliente & Cía <Ltda>',
                identificacion: '800199436',
                responsabilidades: ['O-13'],
                direccion: new Direccion('05001', 'Medellín', '05', 'Antioquia', 'Carrera 43A # 1-50'),
                email: 'compras@example.com',
            ),
            lineas: [
                new Linea('Servicio de consultoría', 2, 500000, [Impuesto::iva(19)], codigo: 'SRV-01'),
                new Linea('Libro (excluido)', 3, 33333.33),
                new Linea('Bebida', 1, 10000, [Impuesto::iva(19), Impuesto::inc(8)]),
            ],
        );
    }
}
