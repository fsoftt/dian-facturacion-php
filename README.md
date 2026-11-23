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
Las notas crédito y débito usan el mismo `Documento` con `TipoDocumento::NotaCredito`
o `NotaDebito` y una `Referencia` a la factura afectada.

### Proceso de habilitación

1. Envíe los documentos del set de pruebas con `enviarSetPruebas($firmado, $testSetId)`.
2. Consulte cada resultado con `consultarZip($respuesta->zipKey())`.
3. Cuando el portal marque el set como aceptado, cambie a `Ambiente::Produccion`,
   use su resolución real y transmita con `enviar()`.

### Lo que debe guardar su aplicación

- El **consecutivo** de cada prefijo y la **secuencia de envío** anual (nombre del archivo).
- El **XML firmado sin modificar** (reformatearlo invalida la firma) y el
  `ApplicationResponse` que devuelve la DIAN.

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
