<?php

declare(strict_types=1);

namespace FacturacionDian\Tests;

use FacturacionDian\Cufe;
use FacturacionDian\Dian;
use FacturacionDian\Formato;
use FacturacionDian\Modelo\Documento;
use FacturacionDian\Modelo\Impuesto;
use FacturacionDian\Modelo\Linea;
use FacturacionDian\Modelo\Parte;
use FacturacionDian\Modelo\Referencia;
use FacturacionDian\Modelo\TipoDocumento;
use FacturacionDian\Ws\ErrorDian;
use FacturacionDian\Ws\RespuestaDian;
use FacturacionDian\Ws\Transporte;
use PHPUnit\Framework\TestCase;

final class DianTest extends TestCase
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    public function testCufeCoincideConElEjemploDelAnexoTecnico(): void
    {
        $cufe = Cufe::hash(
            '323200000129', '2019-01-16', '10:53:10-05:00', 1500000.00, 285000.00, 0.00, 0.00,
            1785000.00, '700085371', '800199436', '693ff6f2a553c3646a063436fd4dd9ded0311471', '1',
        );

        self::assertSame(
            '8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4',
            $cufe,
        );
    }

    public function testDigitoDeVerificacion(): void
    {
        self::assertSame(4, Parte::calcularDv('800197268')); // DIAN
        self::assertSame(1, Parte::calcularDv('899999068')); // Ecopetrol
        self::assertNull(Parte::consumidorFinal()->digitoVerificacion());
    }

    public function testNombreDeArchivo(): void
    {
        self::assertSame('fv09003731150002600000001.xml', Formato::nombreXml(TipoDocumento::Factura, '900373115', 2026, 1));
        self::assertSame('z090037311500026000000ff.zip', Formato::nombreArchivo('z', '900373115', 2026, 255, 'zip'));
    }

    public function testTotales(): void
    {
        $t = Fabrica::factura()->totales();

        self::assertSame(1109999.99, $t->subtotal);
        self::assertSame(1010000.00, $t->baseGravable);
        self::assertSame(191900.00, $t->impuesto(Impuesto::IVA));
        self::assertSame(800.00, $t->impuesto(Impuesto::INC));
        self::assertSame(1302699.99, $t->total);
    }

    public function testFacturaFirmadaEsValida(): void
    {
        $config = Fabrica::configuracion();
        $firmado = (new Dian($config))->preparar(Fabrica::factura(), 1);

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($firmado->xml));
        $x = $this->xpath($doc);

        self::assertSame('Invoice', $doc->documentElement->localName);
        self::assertSame($firmado->cufe, $x->evaluate('string(/*/cbc:UUID)'));
        self::assertSame('CUFE-SHA384', $x->evaluate('string(/*/cbc:UUID/@schemeName)'));
        self::assertSame('SETP990000001', $x->evaluate('string(/*/cbc:ID)'));
        self::assertSame('10:30:00-05:00', $x->evaluate('string(/*/cbc:IssueTime)'));
        self::assertSame('900373115', $x->evaluate('string(//sts:SoftwareProvider/sts:ProviderID)'));
        self::assertSame('Cliente & Cía <Ltda>', $x->evaluate('string(//cac:AccountingCustomerParty//cac:PartyName/cbc:Name)'));
        self::assertSame('1302699.99', $x->evaluate('string(/*/cac:LegalMonetaryTotal/cbc:PayableAmount)'));
        self::assertSame(3.0, $x->evaluate('count(/*/cac:InvoiceLine)'));
        self::assertSame(2.0, $x->evaluate('count(/*/cac:TaxTotal)'));
        self::assertSame(
            hash('sha384', $config->softwareId . $config->softwarePin . 'SETP990000001'),
            $x->evaluate('string(//sts:SoftwareSecurityCode)'),
        );
        self::assertStringContainsString("CUFE: {$firmado->cufe}", $firmado->textoQr);

        $this->assertFirmaXadesValida($doc);
    }

    public function testFirmaDetectaAlteraciones(): void
    {
        $firmado = (new Dian(Fabrica::configuracion()))->preparar(Fabrica::factura(), 1);
        $doc = new \DOMDocument();
        $doc->loadXML(str_replace('1302699.99', '1.00', $firmado->xml));
        $x = $this->xpath($doc);

        $esperado = $x->evaluate('string(//ds:SignedInfo/ds:Reference[1]/ds:DigestValue)');
        $firma = $x->query('//ds:Signature')->item(0);
        $firma->parentNode->removeChild($firma);

        self::assertNotSame($esperado, base64_encode(hash('sha256', $doc->C14N(), true)));
    }

    public function testNotaCredito(): void
    {
        $config = Fabrica::configuracion();
        $nota = new Documento(
            tipo: TipoDocumento::NotaCredito,
            prefijo: 'NC',
            consecutivo: 1,
            fecha: new \DateTimeImmutable('2026-11-24 09:00:00', new \DateTimeZone('America/Bogota')),
            adquirente: Parte::consumidorFinal(),
            lineas: [new Linea('Devolución', 1, 100000, [Impuesto::iva(19)])],
            referencia: new Referencia('SETP990000001', str_repeat('a', 96), new \DateTimeImmutable('2026-11-23'), '1', 'Devolución parcial'),
        );
        $firmado = (new Dian($config))->preparar($nota, 2);

        $doc = new \DOMDocument();
        $doc->loadXML($firmado->xml);
        $x = $this->xpath($doc);

        self::assertSame('CreditNote', $doc->documentElement->localName);
        self::assertStringStartsWith('nc0900373115', $firmado->nombreXml);
        self::assertSame('CUDE-SHA384', $x->evaluate('string(/*/cbc:UUID/@schemeName)'));
        self::assertSame(0.0, $x->evaluate('count(//sts:InvoiceControl)'));
        self::assertSame('SETP990000001', $x->evaluate('string(/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID)'));
        self::assertSame('222222222222', $x->evaluate('string(//cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID)'));
        self::assertSame(1.0, $x->evaluate('count(/*/cac:CreditNoteLine/cbc:CreditedQuantity)'));
        // El CUDE usa el PIN del software en lugar de la clave técnica.
        self::assertSame(
            Cufe::hash('NC1', '2026-11-24', '09:00:00-05:00', 100000, 19000, 0, 0, 119000, '900373115', '222222222222', '12345', '2'),
            $firmado->cufe,
        );
        $this->assertFirmaXadesValida($doc);
    }

    public function testNotaDebitoUsaRequestedMonetaryTotal(): void
    {
        $nota = new Documento(
            tipo: TipoDocumento::NotaDebito,
            prefijo: 'ND',
            consecutivo: 1,
            fecha: new \DateTimeImmutable('2026-11-24 09:00:00-05:00'),
            adquirente: Parte::consumidorFinal(),
            lineas: [new Linea('Intereses', 1, 5000)],
            referencia: new Referencia('SETP990000001', str_repeat('a', 96), new \DateTimeImmutable('2026-11-23'), '1', 'Intereses'),
        );
        $doc = new \DOMDocument();
        $doc->loadXML((new Dian(Fabrica::configuracion()))->preparar($nota, 3)->xml);
        $x = $this->xpath($doc);

        self::assertSame('5000.00', $x->evaluate('string(/*/cac:RequestedMonetaryTotal/cbc:PayableAmount)'));
        self::assertSame(1.0, $x->evaluate('count(/*/cac:DebitNoteLine/cbc:DebitedQuantity)'));
    }

    public function testFacturaFueraDeRangoEsRechazada(): void
    {
        $f = Fabrica::factura();
        $fuera = new Documento($f->tipo, 'SETP', 1, $f->fecha, $f->adquirente, $f->lineas);

        $this->expectException(\InvalidArgumentException::class);
        (new Dian(Fabrica::configuracion()))->preparar($fuera, 1);
    }

    public function testEnvioFirmaElSobreSoapYLeeLaRespuesta(): void
    {
        $transporte = new class implements Transporte {
            public string $url = '';
            public string $accion = '';
            public string $sobre = '';

            public function enviar(string $url, string $accion, string $sobre): string
            {
                [$this->url, $this->accion, $this->sobre] = [$url, $accion, $sobre];

                return '<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'
                    . '<SendBillSyncResponse xmlns="http://wcf.dian.colombia"><SendBillSyncResult xmlns:b="http://schemas.datacontract.org/2004/07/DianResponse" xmlns:c="http://schemas.microsoft.com/2003/10/Serialization/Arrays">'
                    . '<b:ErrorMessage><c:string>Regla: FAJ43b, Notificación: Nombre informado No corresponde al registrado en el RUT</c:string></b:ErrorMessage>'
                    . '<b:IsValid>true</b:IsValid><b:StatusCode>00</b:StatusCode><b:StatusDescription>Procesado Correctamente.</b:StatusDescription>'
                    . '<b:StatusMessage>La Factura electrónica SETP990000001, ha sido autorizada.</b:StatusMessage>'
                    . '<b:XmlBase64Bytes>' . base64_encode('<ApplicationResponse/>') . '</b:XmlBase64Bytes><b:XmlDocumentKey>abc</b:XmlDocumentKey>'
                    . '</SendBillSyncResult></SendBillSyncResponse></s:Body></s:Envelope>';
            }
        };
        $dian = new Dian(Fabrica::configuracion(), $transporte);
        $firmado = $dian->preparar(Fabrica::factura(), 1);
        $respuesta = $dian->enviar($firmado);

        self::assertTrue($respuesta->esValido());
        self::assertSame('00', $respuesta->codigo());
        self::assertCount(1, $respuesta->errores());
        self::assertSame('<ApplicationResponse/>', $respuesta->applicationResponse());
        self::assertSame('https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc', $transporte->url);
        self::assertSame('http://wcf.dian.colombia/IWcfDianCustomerServices/SendBillSync', $transporte->accion);

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($transporte->sobre));
        $x = new \DOMXPath($doc);
        $x->registerNamespace('ds', self::DS);
        $x->registerNamespace('wcf', 'http://wcf.dian.colombia');

        // El ZIP enviado contiene el XML firmado con el nombre esperado.
        self::assertSame($firmado->nombreZip, $x->evaluate('string(//wcf:fileName)'));
        $ruta = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($ruta, base64_decode($x->evaluate('string(//wcf:contentFile)')));
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($ruta));
        self::assertSame($firmado->xml, $zip->getFromName($firmado->nombreXml));
        $zip->close();
        unlink($ruta);

        // Firma WS-Security sobre wsa:To.
        $to = $x->query('//*[local-name()="To"]')->item(0);
        self::assertSame(
            $x->evaluate('string(//ds:DigestValue)'),
            base64_encode(hash('sha256', $to->C14N(true, false, null, ['soap', 'wcf']), true)),
        );
        $this->assertFirmaRsa(
            $x->query('//ds:SignedInfo')->item(0)->C14N(true, false, null, ['wsa', 'soap', 'wcf']),
            $x->evaluate('string(//ds:SignatureValue)'),
            $x->evaluate('string(//*[local-name()="BinarySecurityToken"])'),
        );
    }

    public function testSoapFaultLanzaExcepcion(): void
    {
        $this->expectException(ErrorDian::class);
        $this->expectExceptionMessage('firma inválida');
        new RespuestaDian('<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body><s:Fault>'
            . '<s:Code><s:Value>s:Sender</s:Value></s:Code><s:Reason><s:Text xml:lang="es">firma inválida</s:Text></s:Reason>'
            . '</s:Fault></s:Body></s:Envelope>');
    }

    public function testRespuestaAsincronaDevuelveZipKey(): void
    {
        $r = new RespuestaDian('<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"><s:Body>'
            . '<SendTestSetAsyncResponse xmlns="http://wcf.dian.colombia"><SendTestSetAsyncResult xmlns:b="http://schemas.datacontract.org/2004/07/UploadDocumentResponse">'
            . '<b:ErrorMessageList i:nil="true" xmlns:i="http://www.w3.org/2001/XMLSchema-instance"/><b:ZipKey>4d2c-zip</b:ZipKey>'
            . '</SendTestSetAsyncResult></SendTestSetAsyncResponse></s:Body></s:Envelope>');

        self::assertSame('4d2c-zip', $r->zipKey());
        self::assertNull($r->esValido());
        self::assertSame([], $r->errores());
    }

    /** Verifica de forma independiente las tres referencias y el valor de la firma. */
    private function assertFirmaXadesValida(\DOMDocument $doc): void
    {
        $x = $this->xpath($doc);
        $firma = $x->query('//ext:UBLExtension[2]/ext:ExtensionContent/ds:Signature')->item(0);
        self::assertNotNull($firma, 'La firma debe estar en la segunda extensión');
        $id = $firma->getAttribute('Id');
        $huella = static fn (string $c14n): string => base64_encode(hash('sha256', $c14n, true));
        $digest = fn (int $n): string => $x->evaluate("string(ds:SignedInfo/ds:Reference[{$n}]/ds:DigestValue)", $firma);

        self::assertSame("#{$id}-keyinfo", $x->evaluate('string(ds:SignedInfo/ds:Reference[2]/@URI)', $firma));
        self::assertSame($digest(2), $huella($x->query('ds:KeyInfo', $firma)->item(0)->C14N()));
        $props = $x->query('ds:Object/xades:QualifyingProperties/xades:SignedProperties', $firma)->item(0);
        self::assertSame("{$id}-signedprops", $props->getAttribute('Id'));
        self::assertSame($digest(3), $huella($props->C14N()));

        $certificado = $x->evaluate('string(ds:KeyInfo/ds:X509Data/ds:X509Certificate)', $firma);
        self::assertSame(
            $x->evaluate('string(.//xades:CertDigest/ds:DigestValue)', $firma),
            base64_encode(hash('sha256', base64_decode($certificado), true)),
        );
        self::assertStringContainsString('CN=Prueba\, Firma,O=Empresa de Prueba SAS,C=CO', $x->evaluate('string(.//ds:X509IssuerName)', $firma));
        self::assertSame((string) 0x7fffffff, $x->evaluate('string(.//ds:X509SerialNumber)', $firma));

        $this->assertFirmaRsa(
            $x->query('ds:SignedInfo', $firma)->item(0)->C14N(),
            $x->evaluate('string(ds:SignatureValue)', $firma),
            $certificado,
        );

        // Referencia 1 (enveloped): el documento sin el elemento Signature.
        $esperado = $digest(1);
        $firma->parentNode->removeChild($firma);
        self::assertSame($esperado, $huella($doc->C14N()));
    }

    private function assertFirmaRsa(string $datos, string $firmaBase64, string $certificadoBase64): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($certificadoBase64, 64) . "-----END CERTIFICATE-----\n";
        self::assertSame(1, openssl_verify($datos, base64_decode($firmaBase64), $pem, OPENSSL_ALGO_SHA256));
    }

    private function xpath(\DOMDocument $doc): \DOMXPath
    {
        $x = new \DOMXPath($doc);
        $x->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $x->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $x->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $x->registerNamespace('sts', 'dian:gov:co:facturaelectronica:Structures-2-1');
        $x->registerNamespace('ds', self::DS);
        $x->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

        return $x;
    }
}
