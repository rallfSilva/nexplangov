<?php

namespace App\Licitacao\Regras;

/**
 * Confere o PCA publicado no PNCP com o plano do sistema, item a item pelo número (art. 12, § 1º, e art. 174):
 * o que só existe aqui (a incluir), o que só existe lá (a excluir no portal) e o que mudou (a retificar).
 */
final class ComparacaoPcaPncp
{
    /** Campos conferidos: nome aqui => nome no PNCP. */
    public const CAMPOS = ['descricao' => 'Descrição', 'quantidade' => 'Quantidade', 'valor_unitario' => 'Valor unitário', 'data' => 'Data desejada', 'codigo' => 'Código do catálogo'];

    /**
     * @param  list<array{numero: int, descricao: string, quantidade: float, valor_unitario: float, data: ?string, codigo: ?string}>  $locais
     * @param  list<array<string, mixed>>  $remotos  itens como o PNCP devolve (numeroItem, descricao, quantidade, valorUnitario, dataDesejada, codigoItem)
     * @return array{novos: list<int>, removidos: list<int>, alterados: array<int, list<array{campo: string, aqui: string, pncp: string}>>, iguais: int}
     */
    public static function comparar(array $locais, array $remotos): array
    {
        $aqui = [];
        foreach ($locais as $l) {
            $aqui[(int) $l['numero']] = $l;
        }
        $la = [];
        foreach ($remotos as $r) {
            $la[(int) ($r['numeroItem'] ?? 0)] = [
                'descricao' => (string) ($r['descricao'] ?? ''),
                'quantidade' => (float) ($r['quantidade'] ?? 0),
                'valor_unitario' => (float) ($r['valorUnitario'] ?? 0),
                'data' => isset($r['dataDesejada']) ? substr((string) $r['dataDesejada'], 0, 10) : null,
                'codigo' => isset($r['codigoItem']) && $r['codigoItem'] !== '' ? (string) $r['codigoItem'] : null,
            ];
        }

        $alterados = [];
        $iguais = 0;
        foreach (array_intersect_key($aqui, $la) as $n => $l) {
            $dif = [];
            foreach (self::CAMPOS as $c => $rotulo) {
                if (! self::igual($c, $l[$c] ?? null, $la[$n][$c])) {
                    $dif[] = ['campo' => $rotulo, 'aqui' => self::texto($l[$c] ?? null), 'pncp' => self::texto($la[$n][$c])];
                }
            }
            if ($dif) {
                $alterados[$n] = $dif;
            } else {
                $iguais++;
            }
        }
        ksort($alterados);
        $novos = array_keys(array_diff_key($aqui, $la));
        $removidos = array_keys(array_diff_key($la, $aqui));
        sort($novos);
        sort($removidos);

        return ['novos' => $novos, 'removidos' => $removidos, 'alterados' => $alterados, 'iguais' => $iguais];
    }

    private static function igual(string $campo, mixed $a, mixed $b): bool
    {
        return match ($campo) {
            'quantidade', 'valor_unitario' => abs((float) $a - (float) $b) < 0.00005,
            'descricao' => trim((string) $a) === trim((string) $b),
            default => (string) $a === (string) $b,
        };
    }

    private static function texto(mixed $v): string
    {
        return $v === null || $v === '' ? '—' : (is_float($v) ? rtrim(rtrim(number_format($v, 4, ',', '.'), '0'), ',') : (string) $v);
    }
}
