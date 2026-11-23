<?php

declare(strict_types=1);

namespace FacturacionDian\Ws;

use FacturacionDian\Firma\Certificado;

/**
 * Construye el sobre SOAP 1.2 con WS-Addressing y WS-Security que exige el servicio
 * de la DIAN: BinarySecurityToken X.509 y firma RSA-SHA256 del encabezado wsa:To.
 */
final class SobreSoap
{
    public const NS_WCF = 'http://wcf.dian.colombia';
    private const NS_WSSE = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
    private const NS_WSU = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd';
    private const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const EXC_C14N = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const X509V3 = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-x509-token-profile-1.0#X509v3';

    public function __construct(private readonly Certificado $certificado)
    {
    }

    public static function accion(string $operacion): string
    {
        return self::NS_WCF . '/IWcfDianCustomerServices/' . $operacion;
    }

    /** @param array<string, string> $parametros Parámetros de la operación, en el orden del contrato. */
    public function construir(string $url, string $operacion, array $parametros, ?\DateTimeImmutable $ahora = null): string
    {
        $ahora = ($ahora ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'));
        $sufijo = strtoupper(bin2hex(random_bytes(16)));
        $esc = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $inclusivos = static fn (string $prefijos): string => '<ec:InclusiveNamespaces xmlns:ec="' . self::EXC_C14N . '" PrefixList="' . $prefijos . '"/>';

        $cuerpo = '';
        foreach ($parametros as $nombre => $valor) {
            $cuerpo .= "<wcf:{$nombre}>" . $esc($valor) . "</wcf:{$nombre}>";
        }

        $xml = '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:wcf="' . self::NS_WCF . '">'
            . '<soap:Header xmlns:wsa="http://www.w3.org/2005/08/addressing">'
            . '<wsse:Security xmlns:wsse="' . self::NS_WSSE . '" xmlns:wsu="' . self::NS_WSU . '">'
            . '<wsu:Timestamp wsu:Id="TS-' . $sufijo . '">'
            . '<wsu:Created>' . $ahora->format('Y-m-d\TH:i:s.v\Z') . '</wsu:Created>'
            . '<wsu:Expires>' . $ahora->modify('+5 minutes')->format('Y-m-d\TH:i:s.v\Z') . '</wsu:Expires>'
            . '</wsu:Timestamp>'
            . '<wsse:BinarySecurityToken EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary"'
            . ' ValueType="' . self::X509V3 . '" wsu:Id="X509-' . $sufijo . '">' . $this->certificado->base64() . '</wsse:BinarySecurityToken>'
            . '<ds:Signature xmlns:ds="' . self::NS_DS . '" Id="SIG-' . $sufijo . '">'
            . '<ds:SignedInfo>'
            . '<ds:CanonicalizationMethod Algorithm="' . self::EXC_C14N . '">' . $inclusivos('wsa soap wcf') . '</ds:CanonicalizationMethod>'
            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"/>'
            . '<ds:Reference URI="#ID-' . $sufijo . '">'
            . '<ds:Transforms><ds:Transform Algorithm="' . self::EXC_C14N . '">' . $inclusivos('soap wcf') . '</ds:Transform></ds:Transforms>'
            . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
            . '<ds:DigestValue/>'
            . '</ds:Reference>'
            . '</ds:SignedInfo>'
            . '<ds:SignatureValue/>'
            . '<ds:KeyInfo Id="KI-' . $sufijo . '">'
            . '<wsse:SecurityTokenReference wsu:Id="STR-' . $sufijo . '">'
            . '<wsse:Reference URI="#X509-' . $sufijo . '" ValueType="' . self::X509V3 . '"/>'
            . '</wsse:SecurityTokenReference>'
            . '</ds:KeyInfo>'
            . '</ds:Signature>'
            . '</wsse:Security>'
            . '<wsa:Action>' . self::accion($operacion) . '</wsa:Action>'
            . '<wsa:To xmlns:wsu="' . self::NS_WSU . '" wsu:Id="ID-' . $sufijo . '">' . $esc($url) . '</wsa:To>'
            . '</soap:Header>'
            . '<soap:Body><wcf:' . $operacion . '>' . $cuerpo . '</wcf:' . $operacion . '></soap:Body>'
            . '</soap:Envelope>';

        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', self::NS_DS);
        $uno = static fn (string $consulta): \DOMNode => $xpath->query($consulta)->item(0);

        $uno('//ds:DigestValue')->nodeValue = base64_encode(
            hash('sha256', $uno('//*[local-name()="To"]')->C14N(true, false, null, ['soap', 'wcf']), true)
        );
        $uno('//ds:SignatureValue')->nodeValue = $this->certificado->firmar(
            $uno('//ds:SignedInfo')->C14N(true, false, null, ['wsa', 'soap', 'wcf'])
        );

        return $doc->saveXML($doc->documentElement);
    }
}
