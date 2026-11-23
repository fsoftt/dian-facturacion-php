<?php

declare(strict_types=1);

namespace FacturacionDian\Firma;

/** Certificado digital X.509 con su llave privada, emitido por una ECD acreditada por ONAC. */
final class Certificado
{
    private readonly string $der;
    /** @var array<string, mixed> */
    private readonly array $datos;

    private function __construct(string $certificadoPem, private readonly \OpenSSLAsymmetricKey $llave)
    {
        $x509 = openssl_x509_read($certificadoPem) ?: throw new \InvalidArgumentException('Certificado inválido: ' . openssl_error_string());
        if (!openssl_x509_check_private_key($x509, $llave)) {
            throw new \InvalidArgumentException('La llave privada no corresponde al certificado.');
        }
        openssl_x509_export($x509, $pem);
        $this->der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem));
        $this->datos = openssl_x509_parse($x509);
    }

    /**
     * Carga un archivo .p12/.pfx. Los archivos cifrados con algoritmos antiguos (RC2-40)
     * fallan con OpenSSL 3; conviértalos con `openssl pkcs12 -legacy` o use desdePem().
     */
    public static function desdeP12(string $ruta, string $clave): self
    {
        $contenido = @file_get_contents($ruta);
        if ($contenido === false) {
            throw new \InvalidArgumentException("No se pudo leer el certificado: {$ruta}");
        }
        if (!openssl_pkcs12_read($contenido, $partes, $clave)) {
            throw new \InvalidArgumentException('No se pudo abrir el .p12 (¿clave incorrecta o cifrado antiguo?): ' . openssl_error_string());
        }

        return self::desdePem($partes['cert'], $partes['pkey']);
    }

    public static function desdePem(string $certificadoPem, string $llavePem, ?string $clave = null): self
    {
        $llave = openssl_pkey_get_private($llavePem, $clave) ?: throw new \InvalidArgumentException('Llave privada inválida: ' . openssl_error_string());

        return new self($certificadoPem, $llave);
    }

    /** Certificado en DER codificado en base64, sin encabezados. */
    public function base64(): string
    {
        return base64_encode($this->der);
    }

    /** Huella SHA-256 del certificado en base64 (xades:CertDigest). */
    public function huella(): string
    {
        return base64_encode(hash('sha256', $this->der, true));
    }

    /** Nombre distinguido del emisor, del atributo más específico al más general. */
    public function emisor(): string
    {
        $partes = [];
        foreach (array_reverse($this->datos['issuer']) as $atributo => $valores) {
            foreach ((array) $valores as $valor) {
                $partes[] = $atributo . '=' . addcslashes((string) $valor, ',+"\\<>;');
            }
        }

        return implode(',', $partes);
    }

    /** Número de serie en decimal. */
    public function serial(): string
    {
        $decimal = '0';
        foreach (str_split($this->datos['serialNumberHex']) as $digitoHex) {
            $acarreo = hexdec($digitoHex);
            $resultado = '';
            for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
                $valor = (int) $decimal[$i] * 16 + $acarreo;
                $resultado = ($valor % 10) . $resultado;
                $acarreo = intdiv($valor, 10);
            }
            $decimal = ltrim($acarreo . $resultado, '0') ?: '0';
        }

        return $decimal;
    }

    public function venceEl(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($this->datos['validTo_time_t']);
    }

    /** Firma RSA-SHA256 en base64. */
    public function firmar(string $datos): string
    {
        if (!openssl_sign($datos, $firma, $this->llave, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('No se pudo firmar: ' . openssl_error_string());
        }

        return base64_encode($firma);
    }
}
