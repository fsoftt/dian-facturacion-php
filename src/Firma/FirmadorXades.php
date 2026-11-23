<?php

declare(strict_types=1);

namespace FacturacionDian\Firma;

/**
 * Firma XAdES-EPES envuelta (enveloped) conforme a la política de firma v2 de la DIAN.
 *
 * La firma se inserta en el segundo ext:ExtensionContent del documento UBL y cubre
 * tres referencias: el documento completo, el KeyInfo y las SignedProperties.
 */
final class FirmadorXades
{
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';
    private const C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
    private const RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const POLITICA_URL = 'https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf';
    private const POLITICA_DESCRIPCION = 'Política de firma para facturas electrónicas de la República de Colombia.';
    /** SHA-256 en base64 del PDF de la política de firma v2. */
    private const POLITICA_HUELLA = 'dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=';

    public function __construct(private readonly Certificado $certificado)
    {
    }

    /** @param string $rol Rol del firmante: "supplier" (facturador) o "third party" (proveedor tecnológico). */
    public function firmar(string $xml, ?\DateTimeInterface $momento = null, string $rol = 'supplier'): string
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        if (!$doc->loadXML($xml)) {
            throw new \InvalidArgumentException('El XML a firmar no está bien formado.');
        }

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ext', self::NS_EXT);
        $xpath->registerNamespace('ds', self::NS_DS);
        $destino = $xpath->query('/*/ext:UBLExtensions/ext:UBLExtension[last()]/ext:ExtensionContent')->item(0)
            ?? throw new \InvalidArgumentException('El XML no tiene un ext:ExtensionContent para la firma.');
        if ($destino->hasChildNodes()) {
            throw new \InvalidArgumentException('El último ext:ExtensionContent debe estar vacío.');
        }

        // Referencia 1: el documento sin la firma (transformación enveloped-signature).
        $huellaDocumento = self::huella($doc->C14N());

        $id = 'xmldsig-' . self::uuid();
        $momento = \DateTimeImmutable::createFromInterface($momento ?? new \DateTimeImmutable())
            ->setTimezone(new \DateTimeZone('America/Bogota'));

        $fragmento = $doc->createDocumentFragment();
        $fragmento->appendXML($this->plantilla($id, $momento->format('Y-m-d\TH:i:s.vP'), $rol, $huellaDocumento));
        $destino->appendChild($fragmento);

        // Referencias 2 y 3: se canonicalizan dentro del documento para heredar sus espacios de nombres.
        $referencias = $xpath->query('.//ds:SignedInfo/ds:Reference/ds:DigestValue', $destino);
        $keyInfo = $xpath->query('.//ds:KeyInfo', $destino)->item(0);
        $propiedades = $xpath->query('.//*[local-name()="SignedProperties"]', $destino)->item(0);
        $referencias->item(1)->nodeValue = self::huella($keyInfo->C14N());
        $referencias->item(2)->nodeValue = self::huella($propiedades->C14N());

        $signedInfo = $xpath->query('.//ds:SignedInfo', $destino)->item(0);
        $xpath->query('.//ds:SignatureValue', $destino)->item(0)->nodeValue = $this->certificado->firmar($signedInfo->C14N());

        return $doc->saveXML();
    }

    private function plantilla(string $id, string $momento, string $rol, string $huellaDocumento): string
    {
        $c = $this->certificado;
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $digest = '<ds:DigestMethod Algorithm="' . self::SHA256 . '"/>';

        return '<ds:Signature xmlns:ds="' . self::NS_DS . '" Id="' . $id . '">'
            . '<ds:SignedInfo>'
            . '<ds:CanonicalizationMethod Algorithm="' . self::C14N . '"/>'
            . '<ds:SignatureMethod Algorithm="' . self::RSA_SHA256 . '"/>'
            . '<ds:Reference Id="' . $id . '-ref0" URI="">'
            . '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></ds:Transforms>'
            . $digest . '<ds:DigestValue>' . $huellaDocumento . '</ds:DigestValue>'
            . '</ds:Reference>'
            . '<ds:Reference URI="#' . $id . '-keyinfo">' . $digest . '<ds:DigestValue/></ds:Reference>'
            . '<ds:Reference Type="http://uri.etsi.org/01903#SignedProperties" URI="#' . $id . '-signedprops">'
            . $digest . '<ds:DigestValue/></ds:Reference>'
            . '</ds:SignedInfo>'
            . '<ds:SignatureValue Id="' . $id . '-sigvalue"/>'
            . '<ds:KeyInfo Id="' . $id . '-keyinfo"><ds:X509Data><ds:X509Certificate>' . $c->base64() . '</ds:X509Certificate></ds:X509Data></ds:KeyInfo>'
            . '<ds:Object>'
            . '<xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" xmlns:xades141="http://uri.etsi.org/01903/v1.4.1#" Target="#' . $id . '">'
            . '<xades:SignedProperties Id="' . $id . '-signedprops">'
            . '<xades:SignedSignatureProperties>'
            . '<xades:SigningTime>' . $momento . '</xades:SigningTime>'
            . '<xades:SigningCertificate><xades:Cert>'
            . '<xades:CertDigest>' . $digest . '<ds:DigestValue>' . $c->huella() . '</ds:DigestValue></xades:CertDigest>'
            . '<xades:IssuerSerial><ds:X509IssuerName>' . $esc($c->emisor()) . '</ds:X509IssuerName>'
            . '<ds:X509SerialNumber>' . $c->serial() . '</ds:X509SerialNumber></xades:IssuerSerial>'
            . '</xades:Cert></xades:SigningCertificate>'
            . '<xades:SignaturePolicyIdentifier><xades:SignaturePolicyId>'
            . '<xades:SigPolicyId><xades:Identifier>' . self::POLITICA_URL . '</xades:Identifier>'
            . '<xades:Description>' . self::POLITICA_DESCRIPCION . '</xades:Description></xades:SigPolicyId>'
            . '<xades:SigPolicyHash>' . $digest . '<ds:DigestValue>' . self::POLITICA_HUELLA . '</ds:DigestValue></xades:SigPolicyHash>'
            . '</xades:SignaturePolicyId></xades:SignaturePolicyIdentifier>'
            . '<xades:SignerRole><xades:ClaimedRoles><xades:ClaimedRole>' . $esc($rol) . '</xades:ClaimedRole></xades:ClaimedRoles></xades:SignerRole>'
            . '</xades:SignedSignatureProperties>'
            . '</xades:SignedProperties>'
            . '</xades:QualifyingProperties>'
            . '</ds:Object>'
            . '</ds:Signature>';
    }

    private static function huella(string $canonico): string
    {
        return base64_encode(hash('sha256', $canonico, true));
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
