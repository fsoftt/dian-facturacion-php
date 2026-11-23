<?php

declare(strict_types=1);

namespace FacturacionDian\Modelo;

/** Totales de un documento calculados a partir de sus líneas. */
final class Totales
{
    /** Suma de los valores de línea antes de impuestos (LineExtensionAmount). */
    public readonly float $subtotal;

    /** Suma de las bases de las líneas gravadas (TaxExclusiveAmount). */
    public readonly float $baseGravable;

    public readonly float $totalImpuestos;

    /** Valor a pagar (PayableAmount). */
    public readonly float $total;

    /**
     * Impuestos agrupados por tributo y tarifa.
     *
     * @var array<string, array{nombre: string, valor: float, tarifas: array<string, array{porcentaje: float, base: float, valor: float}>}>
     */
    public readonly array $impuestos;

    /** @param Linea[] $lineas */
    public function __construct(array $lineas)
    {
        $subtotal = 0.0;
        $base = 0.0;
        $impuestos = [];

        foreach ($lineas as $linea) {
            $subtotal += $linea->subtotal();
            if ($linea->impuestos !== []) {
                $base += $linea->subtotal();
            }
            foreach ($linea->impuestos as $impuesto) {
                $tarifa = number_format($impuesto->porcentaje, 2, '.', '');
                $impuestos[$impuesto->codigo] ??= ['nombre' => $impuesto->nombre, 'valor' => 0.0, 'tarifas' => []];
                $impuestos[$impuesto->codigo]['tarifas'][$tarifa] ??= [
                    'porcentaje' => $impuesto->porcentaje, 'base' => 0.0, 'valor' => 0.0,
                ];
                $valor = $linea->valorImpuesto($impuesto);
                $impuestos[$impuesto->codigo]['valor'] += $valor;
                $impuestos[$impuesto->codigo]['tarifas'][$tarifa]['base'] += $linea->subtotal();
                $impuestos[$impuesto->codigo]['tarifas'][$tarifa]['valor'] += $valor;
            }
        }

        $this->subtotal = round($subtotal, 2);
        $this->baseGravable = round($base, 2);
        $this->impuestos = $impuestos;
        $this->totalImpuestos = round(array_sum(array_column($impuestos, 'valor')), 2);
        $this->total = round($this->subtotal + $this->totalImpuestos, 2);
    }

    /** Total de un tributo por su código (01 IVA, 04 INC, 03 ICA). */
    public function impuesto(string $codigo): float
    {
        return round($this->impuestos[$codigo]['valor'] ?? 0.0, 2);
    }

    /** Total de los tributos distintos de IVA. */
    public function otrosImpuestos(): float
    {
        return round($this->totalImpuestos - $this->impuesto(Impuesto::IVA), 2);
    }
}
