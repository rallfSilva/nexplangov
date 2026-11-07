<?php

namespace App\Support;

/**
 * Exportação de relatórios em CSV (separador ponto e vírgula, com BOM para o Excel abrir acentos) e em TXT de largura
 * fixa, para sistemas legados e para leitura em tela. Texto que começa com = + - @ ganha apóstrofo no CSV, para não
 * virar fórmula ao abrir a planilha.
 */
final class Exportacao
{
    /** @param  list<list<mixed>>  $linhas */
    public static function csv(array $cabecalho, array $linhas): string
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $cabecalho, ';', '"', '');
        foreach ($linhas as $l) {
            fputcsv($f, array_map(fn ($v) => self::celulaCsv($v), $l), ';', '"', '');
        }
        rewind($f);
        $s = stream_get_contents($f);
        fclose($f);

        return $s;
    }

    /** @param  list<list<mixed>>  $linhas */
    public static function txt(string $titulo, array $cabecalho, array $linhas): string
    {
        $texto = array_map(fn ($l) => array_map(fn ($v) => self::texto($v), $l), $linhas);
        $larguras = array_map(fn ($i) => min(60, max(mb_strlen($cabecalho[$i]), ...array_map(fn ($l) => mb_strlen($l[$i] ?? ''), $texto ?: [[]]))), array_keys($cabecalho));
        $linha = fn (array $cols) => rtrim(implode('  ', array_map(fn ($i) => self::preencher(mb_substr($cols[$i] ?? '', 0, $larguras[$i]), $larguras[$i]), array_keys($larguras))));
        $sep = str_repeat('-', array_sum($larguras) + 2 * (count($larguras) - 1));

        return implode("\n", [$titulo, $sep, $linha($cabecalho), $sep, ...array_map($linha, $texto), $sep, 'Gerado em '.now()->format('d/m/Y H:i')])."\n";
    }

    private static function celulaCsv(mixed $v): string
    {
        $s = self::texto($v);

        return preg_match('/^[=+\-@]/', $s) && ! is_numeric($s) ? "'".$s : $s;
    }

    private static function texto(mixed $v): string
    {
        return match (true) {
            $v === null => '',
            is_bool($v) => $v ? 'Sim' : 'Não',
            is_float($v) => number_format($v, 2, ',', '.'),
            $v instanceof \DateTimeInterface => $v->format('d/m/Y'),
            default => (string) $v,
        };
    }

    private static function preencher(string $s, int $n): string
    {
        return $s.str_repeat(' ', max(0, $n - mb_strlen($s)));
    }
}
