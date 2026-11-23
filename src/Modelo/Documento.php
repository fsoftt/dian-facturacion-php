<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

/** Factura de venta, nota crédito o nota débito. */
final class Documento
{
    public const CONTADO = '1';
    public const CREDITO = '2';

    public readonly \DateTimeImmutable $fecha;
    public readonly string $tipoOperacion;

    /**
     * @param Linea[]  $lineas
     * @param string   $medioPago Código del medio de pago (10 efectivo, 42 consignación, 47 transferencia, 48 tarjeta crédito, ZZZ otro).
     * @param string[] $notas
     */
    public function __construct(
        public readonly TipoDocumento $tipo,
        public readonly string $prefijo,
        public readonly int $consecutivo,
        \DateTimeInterface $fecha,
        public readonly Parte $adquirente,
        public readonly array $lineas,
        public readonly string $formaPago = self::CONTADO,
        public readonly string $medioPago = '10',
        public readonly ?\DateTimeImmutable $vencimiento = null,
        public readonly string $moneda = 'COP',
        public readonly array $notas = [],
        public readonly ?Referencia $referencia = null,
        ?string $tipoOperacion = null,
    ) {
        // La DIAN valida fecha y hora en hora legal colombiana (UTC-5).
        $this->fecha = \DateTimeImmutable::createFromInterface($fecha)
            ->setTimezone(new \DateTimeZone('America/Bogota'));
        $this->tipoOperacion = $tipoOperacion ?? $tipo->operacionPorDefecto();

        if ($lineas === []) {
            throw new \InvalidArgumentException('El documento debe tener al menos una línea.');
        }
        if ($formaPago === self::CREDITO && $vencimiento === null) {
            throw new \InvalidArgumentException('El pago a crédito requiere fecha de vencimiento.');
        }
        if ($tipo->esNota() && $referencia === null && in_array($this->tipoOperacion, ['20', '30'], true)) {
            throw new \InvalidArgumentException('La nota debe referenciar la factura que afecta.');
        }
    }

    /** Número completo: prefijo + consecutivo. */
    public function numero(): string
    {
        return $this->prefijo . $this->consecutivo;
    }

    public function totales(): Totales
    {
        return new Totales($this->lineas);
    }
}
