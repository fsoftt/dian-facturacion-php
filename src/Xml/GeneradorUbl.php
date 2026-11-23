<?php

declare(strict_types=1);

namespace FacturacionDian\Xml;

use FacturacionDian\Configuracion;
use FacturacionDian\Cufe;
use FacturacionDian\Formato;
use FacturacionDian\Modelo\Direccion;
use FacturacionDian\Modelo\Documento;
use FacturacionDian\Modelo\Linea;
use FacturacionDian\Modelo\Parte;
use FacturacionDian\Modelo\TipoDocumento;

/**
 * Genera el XML UBL 2.1 (sin firmar) de una factura o nota según el anexo técnico
 * de factura electrónica de venta de la DIAN.
 *
 * El XML se emite sin espacios ni saltos de línea entre elementos: cualquier
 * reformateo posterior a la firma la invalida.
 */
final class GeneradorUbl
{
    private const NIT_DIAN = '800197268';
    private const AGENCIA = [
        'schemeAgencyID' => '195',
        'schemeAgencyName' => 'CO, DIAN (Dirección de Impuestos y Aduanas Nacionales)',
    ];

    private \XMLWriter $w;
    private string $moneda;

    public function generar(Documento $doc, Configuracion $config, string $cufe): string
    {
        $tipo = $doc->tipo;
        $raiz = $tipo->raiz();
        $this->moneda = $doc->moneda;
        $totales = $doc->totales();

        $this->w = $w = new \XMLWriter();
        $w->openMemory();
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement($raiz);
        $ns = "urn:oasis:names:specification:ubl:schema:xsd:{$raiz}-2";
        foreach ([
            'xmlns' => $ns,
            'xmlns:cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'xmlns:cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'xmlns:ds' => 'http://www.w3.org/2000/09/xmldsig#',
            'xmlns:ext' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2',
            'xmlns:sts' => 'dian:gov:co:facturaelectronica:Structures-2-1',
            'xmlns:xades' => 'http://uri.etsi.org/01903/v1.3.2#',
            'xmlns:xades141' => 'http://uri.etsi.org/01903/v1.4.1#',
            'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
            'xsi:schemaLocation' => "{$ns} http://docs.oasis-open.org/ubl/os-UBL-2.1/xsd/maindoc/UBL-{$raiz}-2.1.xsd",
        ] as $atributo => $valor) {
            $w->writeAttribute($atributo, $valor);
        }

        $this->extensiones($doc, $config, $cufe);

        $this->el('cbc:UBLVersionID', 'UBL 2.1');
        $this->el('cbc:CustomizationID', $doc->tipoOperacion);
        $this->el('cbc:ProfileID', $tipo->perfil());
        $this->el('cbc:ProfileExecutionID', $config->ambiente->value);
        $this->el('cbc:ID', $doc->numero());
        $this->el('cbc:UUID', $cufe, ['schemeID' => $config->ambiente->value, 'schemeName' => $tipo->esquemaUuid()]);
        $this->el('cbc:IssueDate', $doc->fecha->format('Y-m-d'));
        $this->el('cbc:IssueTime', $doc->fecha->format('H:i:sP'));
        if ($tipo === TipoDocumento::Factura) {
            if ($doc->vencimiento) {
                $this->el('cbc:DueDate', $doc->vencimiento->format('Y-m-d'));
            }
            $this->el('cbc:InvoiceTypeCode', $tipo->value);
        } elseif ($tipo === TipoDocumento::NotaCredito) {
            $this->el('cbc:CreditNoteTypeCode', $tipo->value);
        }
        foreach ($doc->notas as $nota) {
            $this->el('cbc:Note', $nota);
        }
        $this->el('cbc:DocumentCurrencyCode', $doc->moneda);
        $this->el('cbc:LineCountNumeric', (string) count($doc->lineas));

        if ($doc->referencia) {
            $ref = $doc->referencia;
            $w->startElement('cac:DiscrepancyResponse');
            $this->el('cbc:ReferenceID', $ref->numero);
            $this->el('cbc:ResponseCode', $ref->concepto);
            $this->el('cbc:Description', $ref->descripcion);
            $w->endElement();
            $w->startElement('cac:BillingReference');
            $w->startElement('cac:InvoiceDocumentReference');
            $this->el('cbc:ID', $ref->numero);
            $this->el('cbc:UUID', $ref->cufe, ['schemeName' => 'CUFE-SHA384']);
            $this->el('cbc:IssueDate', $ref->fecha->format('Y-m-d'));
            $w->endElement();
            $w->endElement();
        }

        $this->parte('cac:AccountingSupplierParty', $config->emisor, $config->resolucion?->prefijo ?? $doc->prefijo);
        $this->parte('cac:AccountingCustomerParty', $doc->adquirente, null);

        $w->startElement('cac:PaymentMeans');
        $this->el('cbc:ID', $doc->formaPago);
        $this->el('cbc:PaymentMeansCode', $doc->medioPago);
        if ($doc->vencimiento) {
            $this->el('cbc:PaymentDueDate', $doc->vencimiento->format('Y-m-d'));
        }
        $w->endElement();

        foreach ($totales->impuestos as $codigo => $impuesto) {
            $w->startElement('cac:TaxTotal');
            $this->monto('cbc:TaxAmount', $impuesto['valor']);
            foreach ($impuesto['tarifas'] as $tarifa) {
                $this->subtotalImpuesto($tarifa['base'], $tarifa['valor'], $tarifa['porcentaje'], (string) $codigo, $impuesto['nombre']);
            }
            $w->endElement();
        }

        $w->startElement($tipo === TipoDocumento::NotaDebito ? 'cac:RequestedMonetaryTotal' : 'cac:LegalMonetaryTotal');
        $this->monto('cbc:LineExtensionAmount', $totales->subtotal);
        $this->monto('cbc:TaxExclusiveAmount', $totales->baseGravable);
        $this->monto('cbc:TaxInclusiveAmount', $totales->total);
        $this->monto('cbc:PayableAmount', $totales->total);
        $w->endElement();

        foreach (array_values($doc->lineas) as $i => $linea) {
            $this->linea($tipo, $i + 1, $linea);
        }

        $w->endElement();
        $w->endDocument();

        return $w->outputMemory();
    }

    private function extensiones(Documento $doc, Configuracion $config, string $cufe): void
    {
        $w = $this->w;
        $totales = $doc->totales();

        $w->startElement('ext:UBLExtensions');
        $w->startElement('ext:UBLExtension');
        $w->startElement('ext:ExtensionContent');
        $w->startElement('sts:DianExtensions');

        if ($doc->tipo === TipoDocumento::Factura) {
            $res = $config->resolucion ?? throw new \LogicException('La factura requiere una resolución de numeración.');
            if ($doc->prefijo !== $res->prefijo || $doc->consecutivo < $res->desde || $doc->consecutivo > $res->hasta) {
                throw new \InvalidArgumentException("El número {$doc->numero()} está fuera del rango autorizado.");
            }
            $w->startElement('sts:InvoiceControl');
            $this->el('sts:InvoiceAuthorization', $res->numero);
            $w->startElement('sts:AuthorizationPeriod');
            $this->el('cbc:StartDate', $res->vigenteDesde->format('Y-m-d'));
            $this->el('cbc:EndDate', $res->vigenteHasta->format('Y-m-d'));
            $w->endElement();
            $w->startElement('sts:AuthorizedInvoices');
            if ($res->prefijo !== '') {
                $this->el('sts:Prefix', $res->prefijo);
            }
            $this->el('sts:From', (string) $res->desde);
            $this->el('sts:To', (string) $res->hasta);
            $w->endElement();
            $w->endElement();
        }

        $w->startElement('sts:InvoiceSource');
        $this->el('cbc:IdentificationCode', 'CO', [
            'listAgencyID' => '6',
            'listAgencyName' => 'United Nations Economic Commission for Europe',
            'listSchemeURI' => 'urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1',
        ]);
        $w->endElement();

        $w->startElement('sts:SoftwareProvider');
        $this->el('sts:ProviderID', $config->nitProveedorSoftware, self::AGENCIA + [
            'schemeID' => (string) Parte::calcularDv($config->nitProveedorSoftware),
            'schemeName' => Parte::TIPO_NIT,
        ]);
        $this->el('sts:SoftwareID', $config->softwareId, self::AGENCIA);
        $w->endElement();

        $this->el(
            'sts:SoftwareSecurityCode',
            Cufe::codigoSeguridadSoftware($config->softwareId, $config->softwarePin, $doc->numero()),
            self::AGENCIA,
        );

        $w->startElement('sts:AuthorizationProvider');
        $this->el('sts:AuthorizationProviderID', self::NIT_DIAN, self::AGENCIA + ['schemeID' => '4', 'schemeName' => Parte::TIPO_NIT]);
        $w->endElement();

        $this->el('sts:QRCode', self::textoQr($doc, $config, $cufe));

        $w->endElement(); // sts:DianExtensions
        $w->endElement(); // ext:ExtensionContent
        $w->endElement(); // ext:UBLExtension

        // Segunda extensión: aquí inserta la firma el FirmadorXades.
        $w->startElement('ext:UBLExtension');
        $w->startElement('ext:ExtensionContent');
        $w->fullEndElement();
        $w->endElement();
        $w->endElement(); // ext:UBLExtensions
    }

    /** Contenido del código QR de la representación gráfica. */
    public static function textoQr(Documento $doc, Configuracion $config, string $cufe): string
    {
        $t = $doc->totales();

        return implode("\n", [
            'NumFac: ' . $doc->numero(),
            'FecFac: ' . $doc->fecha->format('Y-m-d'),
            'HorFac: ' . $doc->fecha->format('H:i:sP'),
            'NitFac: ' . $config->emisor->identificacion,
            'DocAdq: ' . $doc->adquirente->identificacion,
            'ValFac: ' . Formato::monto($t->subtotal),
            'ValIva: ' . Formato::monto($t->impuesto('01')),
            'ValOtroIm: ' . Formato::monto($t->otrosImpuestos()),
            'ValTolFac: ' . Formato::monto($t->total),
            ($doc->tipo->esNota() ? 'CUDE: ' : 'CUFE: ') . $cufe,
            $config->ambiente->urlConsulta($cufe),
        ]);
    }

    private function parte(string $etiqueta, Parte $p, ?string $prefijoNumeracion): void
    {
        $w = $this->w;
        $esEmisor = $prefijoNumeracion !== null;
        $idAttrs = self::AGENCIA + ($p->digitoVerificacion() !== null ? ['schemeID' => $p->digitoVerificacion()] : [])
            + ['schemeName' => $p->tipoIdentificacion];

        $w->startElement($etiqueta);
        $this->el('cbc:AdditionalAccountID', $p->tipoPersona);
        $w->startElement('cac:Party');

        if (!$esEmisor && $p->tipoPersona === Parte::PERSONA_NATURAL) {
            $w->startElement('cac:PartyIdentification');
            $this->el('cbc:ID', $p->identificacion, $idAttrs);
            $w->endElement();
        }

        $w->startElement('cac:PartyName');
        $this->el('cbc:Name', $p->nombreComercial ?? $p->razonSocial);
        $w->endElement();

        if ($p->direccion) {
            $w->startElement('cac:PhysicalLocation');
            $this->direccion('cac:Address', $p->direccion);
            $w->endElement();
        } elseif ($esEmisor) {
            throw new \InvalidArgumentException('El emisor requiere dirección.');
        }

        $w->startElement('cac:PartyTaxScheme');
        $this->el('cbc:RegistrationName', $p->razonSocial);
        $this->el('cbc:CompanyID', $p->identificacion, $idAttrs);
        $this->el('cbc:TaxLevelCode', implode(';', $p->responsabilidades), ['listName' => $p->regimen]);
        if ($p->direccion) {
            $this->direccion('cac:RegistrationAddress', $p->direccion);
        }
        $w->startElement('cac:TaxScheme');
        $this->el('cbc:ID', $p->tributo);
        $this->el('cbc:Name', $p->nombreTributo());
        $w->endElement();
        $w->endElement();

        $w->startElement('cac:PartyLegalEntity');
        $this->el('cbc:RegistrationName', $p->razonSocial);
        $this->el('cbc:CompanyID', $p->identificacion, $idAttrs);
        if ($esEmisor) {
            $w->startElement('cac:CorporateRegistrationScheme');
            $this->el('cbc:ID', $prefijoNumeracion);
            if ($p->matriculaMercantil) {
                $this->el('cbc:Name', $p->matriculaMercantil);
            }
            $w->endElement();
        }
        $w->endElement();

        if ($p->telefono || $p->email) {
            $w->startElement('cac:Contact');
            if ($p->telefono) {
                $this->el('cbc:Telephone', $p->telefono);
            }
            if ($p->email) {
                $this->el('cbc:ElectronicMail', $p->email);
            }
            $w->endElement();
        }

        $w->endElement(); // cac:Party
        $w->endElement();
    }

    private function direccion(string $etiqueta, Direccion $d): void
    {
        $w = $this->w;
        $w->startElement($etiqueta);
        $this->el('cbc:ID', $d->codigoMunicipio);
        $this->el('cbc:CityName', $d->ciudad);
        if ($d->codigoPostal) {
            $this->el('cbc:PostalZone', $d->codigoPostal);
        }
        $this->el('cbc:CountrySubentity', $d->departamento);
        $this->el('cbc:CountrySubentityCode', $d->codigoDepartamento);
        $w->startElement('cac:AddressLine');
        $this->el('cbc:Line', $d->linea);
        $w->endElement();
        $w->startElement('cac:Country');
        $this->el('cbc:IdentificationCode', $d->codigoPais);
        $this->el('cbc:Name', $d->pais, ['languageID' => 'es']);
        $w->endElement();
        $w->endElement();
    }

    private function linea(TipoDocumento $tipo, int $numero, Linea $linea): void
    {
        $w = $this->w;
        [$etiqueta, $cantidad] = match ($tipo) {
            TipoDocumento::Factura => ['cac:InvoiceLine', 'cbc:InvoicedQuantity'],
            TipoDocumento::NotaCredito => ['cac:CreditNoteLine', 'cbc:CreditedQuantity'],
            TipoDocumento::NotaDebito => ['cac:DebitNoteLine', 'cbc:DebitedQuantity'],
        };

        $w->startElement($etiqueta);
        $this->el('cbc:ID', (string) $numero);
        $this->el($cantidad, self::cantidad($linea->cantidad), ['unitCode' => $linea->unidad]);
        $this->monto('cbc:LineExtensionAmount', $linea->subtotal());

        // Un TaxTotal por tributo en la línea.
        $porTributo = [];
        foreach ($linea->impuestos as $impuesto) {
            $porTributo[$impuesto->codigo][] = $impuesto;
        }
        foreach ($porTributo as $impuestos) {
            $w->startElement('cac:TaxTotal');
            $this->monto('cbc:TaxAmount', array_sum(array_map($linea->valorImpuesto(...), $impuestos)));
            foreach ($impuestos as $impuesto) {
                $this->subtotalImpuesto(
                    $linea->subtotal(),
                    $linea->valorImpuesto($impuesto),
                    $impuesto->porcentaje,
                    $impuesto->codigo,
                    $impuesto->nombre,
                );
            }
            $w->endElement();
        }

        $w->startElement('cac:Item');
        $this->el('cbc:Description', $linea->descripcion);
        if ($linea->codigo !== null) {
            $w->startElement('cac:StandardItemIdentification');
            $this->el('cbc:ID', $linea->codigo, ['schemeID' => $linea->estandarCodigo]);
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('cac:Price');
        $this->monto('cbc:PriceAmount', $linea->precioUnitario);
        $this->el('cbc:BaseQuantity', self::cantidad(1), ['unitCode' => $linea->unidad]);
        $w->endElement();

        $w->endElement();
    }

    private function subtotalImpuesto(float $base, float $valor, float $porcentaje, string $codigo, string $nombre): void
    {
        $w = $this->w;
        $w->startElement('cac:TaxSubtotal');
        $this->monto('cbc:TaxableAmount', $base);
        $this->monto('cbc:TaxAmount', $valor);
        $w->startElement('cac:TaxCategory');
        $this->el('cbc:Percent', number_format($porcentaje, 2, '.', ''));
        $w->startElement('cac:TaxScheme');
        $this->el('cbc:ID', $codigo);
        $this->el('cbc:Name', $nombre);
        $w->endElement();
        $w->endElement();
        $w->endElement();
    }

    /** @param array<string, string> $atributos */
    private function el(string $nombre, string $texto, array $atributos = []): void
    {
        $this->w->startElement($nombre);
        foreach ($atributos as $atributo => $valor) {
            $this->w->writeAttribute($atributo, $valor);
        }
        $this->w->text($texto);
        $this->w->endElement();
    }

    private function monto(string $nombre, float $valor): void
    {
        $this->el($nombre, Formato::monto($valor), ['currencyID' => $this->moneda]);
    }

    private static function cantidad(float $cantidad): string
    {
        return number_format($cantidad, 6, '.', '');
    }
}
