<?php

if (! function_exists('reais')) {
    /** R$ 1.234,56 */
    function reais(float|int|string|null $valor, int $casas = 2): string
    {
        return 'R$ '.number_format((float) $valor, $casas, ',', '.');
    }
}

if (! function_exists('numero_br')) {
    function numero_br(float|int|string|null $valor, int $casas = 2): string
    {
        return number_format((float) $valor, $casas, ',', '.');
    }
}

if (! function_exists('decimal_br')) {
    /** Converte "1.234,56" ou "1234.56" para float. */
    function decimal_br(mixed $valor): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $texto = trim((string) $valor);

        if (str_contains($texto, ',')) {
            $texto = str_replace(['.', ','], ['', '.'], $texto);
        }

        return is_numeric($texto) ? (float) $texto : null;
    }
}
