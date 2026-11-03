<?php

namespace App\Pncp;

/**
 * Guardamos a referência do PNCP como "ano/sequencial", extraída do cabeçalho location da resposta 201
 * (ex.: .../v1/orgaos/00000000000191/compras/2026/15 vira "2026/15").
 */
final class NumeroControle
{
    public static function deLocation(?string $location): ?string
    {
        if (! $location || ! preg_match('~/(\d{4})/(\d+)/?$~', $location, $m)) {
            return null;
        }

        return "{$m[1]}/{$m[2]}";
    }

    /** @return array{0: ?int, 1: ?int} */
    public static function anoSequencial(?string $numero): array
    {
        if (! $numero || ! preg_match('~^(\d{4})/(\d+)$~', $numero, $m)) {
            return [null, null];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** Último número do caminho (ata: .../atas/3; resultado: .../resultados/1). */
    public static function ultimoSequencial(?string $location): ?int
    {
        if (! $location || ! preg_match('~/(\d+)/?$~', $location, $m)) {
            return null;
        }

        return (int) $m[1];
    }
}
