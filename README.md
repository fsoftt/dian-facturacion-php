# Facturación electrónica DIAN para software propio (PHP)

Librería PHP para integrarse directamente con la facturación electrónica de la
DIAN (Colombia) en la modalidad **software propio**, sin proveedor tecnológico:

- Genera el XML **UBL 2.1** de factura de venta, nota crédito y nota débito.
- Calcula **CUFE / CUDE**, el código de seguridad del software y el texto del QR.
- Firma con **XAdES-EPES** (política de firma v2 de la DIAN, RSA-SHA256).
- Consume el servicio web **WcfDianCustomerServices** (SOAP 1.2 + WS-Security):
  `SendBillSync`, `SendBillAsync`, `SendTestSetAsync`, `GetStatus`, `GetStatusZip`,
  `GetNumberingRange`, `GetXmlByDocumentKey`.

Sin dependencias de terceros: solo PHP ≥ 8.1 con `dom`, `openssl`, `curl`, `zip` y `xmlwriter`.

## Instalación

Es un paquete de Composer con autocarga PSR-4 (`FacturacionDian\`), así que funciona
en cualquier proyecto PHP: Laravel, Symfony, CodeIgniter, WordPress o PHP sin framework.

Mientras no esté publicado en Packagist, agregue el repositorio al `composer.json`
de su proyecto:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/fsoftt/dian-facturacion-php" }
    ]
}
```

```bash
composer require fsoftt/dian-facturacion:dev-main
```

Con un tag publicado (por ejemplo `v0.1.0`) puede fijar la versión: `composer require fsoftt/dian-facturacion:^0.1`.
En proyectos sin framework solo falta `require 'vendor/autoload.php';`.

## Qué necesita antes de empezar

1. **Certificado digital** de persona jurídica o natural emitido por una entidad de
   certificación acreditada por ONAC (Andes SCD, Certicámara, GSE, etc.), en `.p12`.
2. Registrarse en el **portal de habilitación** de la DIAN como facturador electrónico
   con *software propio* y crear el software. De allí salen:
   - el **identificador del software** (`softwareId`),
   - el **PIN** que usted define (`softwarePin`),
   - el **TestSetId** y el rango de numeración de pruebas con su clave técnica.
3. Para producción: una **resolución de numeración** asociada al software. La clave
   técnica se consulta con `consultarRangos()`.

## Uso

```php
use FacturacionDian\{Ambiente, Configuracion, Dian};
use FacturacionDian\Firma\Certificado;
use FacturacionDian\Modelo\{Direccion, Documento, Impuesto, Linea, Parte, Resolucion, TipoDocumento};

$config = new Configuracion(
    ambiente: Ambiente::Habilitacion,
    emisor: new Parte(
        razonSocial: 'Mi Empresa SAS',
        identificacion: '900373115',            // NIT sin dígito de verificación
        responsabilidades: ['O-13', 'O-15'],
        direccion: new Direccion('11001', 'Bogotá, D.C.', '11', 'Bogotá', 'Calle 100 # 10-20'),
    ),
    softwareId: '...',
    softwarePin: '...',
    certificado: Certificado::desdeP12('/ruta/certificado.p12', 'clave'),
    resolucion: new Resolucion('18760000001', 'SETP', 990000000, 995000000,
        new DateTimeImmutable('2019-01-19'), new DateTimeImmutable('2030-01-19'),
        'fc8eac422eba16e22ffd8c6f94b3f40a6e38162c'),
);
$dian = new Dian($config);

$factura = new Documento(
    tipo: TipoDocumento::Factura,
    prefijo: 'SETP',
    consecutivo: 990000001,
    fecha: new DateTimeImmutable(),
    adquirente: Parte::consumidorFinal(),
    lineas: [new Linea('Servicio', cantidad: 1, precioUnitario: 100000, impuestos: [Impuesto::iva(19)])],
);

$firmado = $dian->preparar($factura, secuenciaEnvio: 1);   // XML firmado + CUFE + QR
$respuesta = $dian->enviar($firmado);                      // SendBillSync

if ($respuesta->esValido()) {
    // Guarde $firmado->xml y $respuesta->applicationResponse() tal cual.
} else {
    print_r($respuesta->errores());                        // "Regla: FAD06, Rechazo: ..."
}
```

Ejemplo completo en [`examples/enviar_factura.php`](examples/enviar_factura.php).

### Nota crédito o débito

```php
use FacturacionDian\Modelo\Referencia;

$nota = new Documento(
    tipo: TipoDocumento::NotaCredito,           // o TipoDocumento::NotaDebito
    prefijo: 'NC',
    consecutivo: 1,
    fecha: new DateTimeImmutable(),
    adquirente: Parte::consumidorFinal(),
    lineas: [new Linea('Devolución', cantidad: 1, precioUnitario: 100000, impuestos: [Impuesto::iva(19)])],
    referencia: new Referencia(
        numero: 'SETP990000001',                // Factura afectada
        cufe: $cufeDeLaFactura,
        fecha: new DateTimeImmutable('2026-11-23'),
        concepto: '1',                          // 1 devolución parcial, 2 anulación, 3 descuento...
        descripcion: 'Devolución parcial',
    ),
);
$respuesta = $dian->enviar($dian->preparar($nota, secuenciaEnvio: 2));
```

### Consultas

```php
$dian->consultar($cufe);          // Estado de un documento por CUFE/CUDE
$dian->consultarZip($zipKey);     // Resultado de un envío asíncrono o del set de pruebas
$dian->consultarRangos();         // Rangos de numeración y clave técnica
```

Toda respuesta expone `esValido()`, `codigo()`, `descripcion()`, `mensaje()`, `errores()`
y `applicationResponse()`. Los fallos de comunicación y los SOAP Fault lanzan
`FacturacionDian\Ws\ErrorDian`.

### Proceso de habilitación

1. Envíe los documentos del set de pruebas con `enviarSetPruebas($firmado, $testSetId)`.
2. Consulte cada resultado con `consultarZip($respuesta->zipKey())`.
3. Cuando el portal marque el set como aceptado, cambie a `Ambiente::Produccion`,
   use su resolución real y transmita con `enviar()`.

### Lo que debe guardar su aplicación

- El **consecutivo** de cada prefijo y la **secuencia de envío** anual (nombre del archivo).
- El **XML firmado sin modificar** (reformatearlo invalida la firma) y el
  `ApplicationResponse` que devuelve la DIAN.

## Enlaces de la DIAN

| Recurso | Enlace |
| --- | --- |
| Micrositio de facturación electrónica | <https://micrositios.dian.gov.co/sistema-de-facturacion-electronica/> |
| Documentación técnica (anexo técnico, XSD, cajas de herramientas) | <https://micrositios.dian.gov.co/sistema-de-facturacion-electronica/documentacion-tecnica/> |
| Portal de **habilitación** (registro del software, set de pruebas) | <https://catalogo-vpfe-hab.dian.gov.co/User/Login> |
| Portal de **producción** (facturando electrónicamente) | <https://catalogo-vpfe.dian.gov.co/User/Login> |
| Servicio web de habilitación (WSDL) | <https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc?wsdl> |
| Servicio web de producción (WSDL) | <https://vpfe.dian.gov.co/WcfDianCustomerServices.svc?wsdl> |
| Política de firma v2 | <https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf> |
| Portal general de la DIAN (RUT, resoluciones de numeración) | <https://www.dian.gov.co/> |

La DIAN reorganiza su sitio con frecuencia; si un enlace del micrositio cambia, las
URL de los portales y de los servicios web son las que usa la librería y son estables.

## Estado y alcance

Verificado localmente:

- El CUFE reproduce el ejemplo del anexo técnico.
- La firma XAdES (tres referencias y valor de firma) valida con una implementación
  independiente (`xmlsec`), además de las pruebas propias (`composer test`).

**Aún no se ha probado contra el ambiente de habilitación de la DIAN**: eso requiere
su certificado, su software registrado y su TestSetId. Es normal que las primeras
transmisiones devuelvan rechazos por reglas de validación que dependen de sus datos
(RUT, responsabilidades, resolución); `errores()` indica la regla exacta.

No incluido todavía:

- Descuentos y cargos (`AllowanceCharge`), retenciones (`WithholdingTaxTotal`), anticipos.
- Factura de exportación, contingencia y otras monedas con tasa de cambio.
- `AttachedDocument` (contenedor que se entrega al adquirente) y la representación gráfica (PDF/QR).
- Eventos RADIAN, documento soporte, nómina electrónica y documento equivalente POS.
- Validación del XML contra los XSD de UBL.

## Desarrollo

```bash
composer install
composer test
```

Nunca versione el `.p12`, su clave ni el PIN del software; el `.gitignore` excluye
`*.p12`, `*.pfx`, `*.pem` y `.env`.

## Licencia

[MIT](LICENSE). Este proyecto no está afiliado a la DIAN ni avalado por ella.
