<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

enum TipoDocumento: string
{
    case Factura = '01';
    case NotaCredito = '91';
    case NotaDebito = '92';

    /** Elemento raíz UBL. */
    public function raiz(): string
    {
        return match ($this) {
            self::Factura => 'Invoice',
            self::NotaCredito => 'CreditNote',
            self::NotaDebito => 'DebitNote',
        };
    }

    public function perfil(): string
    {
        return match ($this) {
            self::Factura => 'DIAN 2.1: Factura Electrónica de Venta',
            self::NotaCredito => 'DIAN 2.1: Nota Crédito de Factura Electrónica de Venta',
            self::NotaDebito => 'DIAN 2.1: Nota Débito de Factura Electrónica de Venta',
        };
    }

    /** Tipo de operación (CustomizationID) por defecto. */
    public function operacionPorDefecto(): string
    {
        return match ($this) {
            self::Factura => '10',     // Estándar
            self::NotaCredito => '20', // Referencia a una factura electrónica
            self::NotaDebito => '30',  // Referencia a una factura electrónica
        };
    }

    /** Las facturas llevan CUFE (clave técnica); las notas llevan CUDE (PIN del software). */
    public function esquemaUuid(): string
    {
        return $this === self::Factura ? 'CUFE-SHA384' : 'CUDE-SHA384';
    }

    /** Prefijo del nombre de archivo XML según el anexo técnico. */
    public function prefijoArchivo(): string
    {
        return match ($this) {
            self::Factura => 'fv',
            self::NotaCredito => 'nc',
            self::NotaDebito => 'nd',
        };
    }

    public function esNota(): bool
    {
        return $this !== self::Factura;
    }
}
