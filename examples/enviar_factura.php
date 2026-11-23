<?php

/**
 * Ejemplo: generar, firmar y enviar una factura al ambiente de habilitación.
 *
 *   DIAN_P12=/ruta/certificado.p12 DIAN_P12_CLAVE=... DIAN_SOFTWARE_ID=... DIAN_PIN=... \
 *   DIAN_TEST_SET_ID=... php examples/enviar_factura.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use FacturacionDian\Ambiente;
use FacturacionDian\Configuracion;
use FacturacionDian\Dian;
use FacturacionDian\Firma\Certificado;
use FacturacionDian\Modelo\Direccion;
use FacturacionDian\Modelo\Documento;
use FacturacionDian\Modelo\Impuesto;
use FacturacionDian\Modelo\Linea;
use FacturacionDian\Modelo\Parte;
use FacturacionDian\Modelo\Resolucion;
use FacturacionDian\Modelo\TipoDocumento;

$config = new Configuracion(
    ambiente: Ambiente::Habilitacion,
    emisor: new Parte(
        razonSocial: 'Mi Empresa SAS',               // Igual que en el RUT
        identificacion: '900373115',                 // NIT sin dígito de verificación
        responsabilidades: ['O-13', 'O-15'],
        direccion: new Direccion('11001', 'Bogotá, D.C.', '11', 'Bogotá', 'Calle 100 # 10-20', '110111'),
        email: 'facturacion@miempresa.com',
    ),
    softwareId: getenv('DIAN_SOFTWARE_ID'),
    softwarePin: getenv('DIAN_PIN'),
    certificado: Certificado::desdeP12(getenv('DIAN_P12'), getenv('DIAN_P12_CLAVE')),
    // Rango de pruebas que entrega el portal de habilitación junto con el TestSetId.
    resolucion: new Resolucion(
        numero: '18760000001',
        prefijo: 'SETP',
        desde: 990000000,
        hasta: 995000000,
        vigenteDesde: new DateTimeImmutable('2019-01-19'),
        vigenteHasta: new DateTimeImmutable('2030-01-19'),
        claveTecnica: 'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c',
    ),
);

$dian = new Dian($config);

$factura = new Documento(
    tipo: TipoDocumento::Factura,
    prefijo: 'SETP',
    consecutivo: 990000001,
    fecha: new DateTimeImmutable(),
    adquirente: Parte::consumidorFinal(),
    lineas: [
        new Linea('Servicio de consultoría', cantidad: 1, precioUnitario: 100000, impuestos: [Impuesto::iva(19)]),
    ],
);

// El consecutivo de envío del año lo debe persistir su aplicación.
$firmado = $dian->preparar($factura, secuenciaEnvio: 1);
file_put_contents(__DIR__ . '/' . $firmado->nombreXml, $firmado->xml);
echo "CUFE: {$firmado->cufe}\n";

// Durante la habilitación se envía al set de pruebas y luego se consulta el resultado.
$envio = $dian->enviarSetPruebas($firmado, getenv('DIAN_TEST_SET_ID'));
echo "ZipKey: {$envio->zipKey()}\n";
sleep(5);
$estado = $dian->consultarZip($envio->zipKey());

// Una vez habilitado (y en producción) se usa el envío síncrono:
// $estado = $dian->enviar($firmado);

echo "Válido: " . var_export($estado->esValido(), true) . "\n";
echo "{$estado->codigo()} {$estado->descripcion()}\n";
foreach ($estado->errores() as $error) {
    echo " - {$error}\n";
}
