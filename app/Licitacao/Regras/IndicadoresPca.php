<?php

namespace App\Licitacao\Regras;

/**
 * Indicadores de execução do PCA (art. 12, VII, e § 1º; art. 18, caput): quanto do que se contrata estava
 * planejado, quanto ficou fora do plano, quais demandas aprovadas passaram da data sem virar processo e o
 * índice de planejamento tardio (contratação fora do PCA ou com DFD enviado depois do prazo).
 */
final class IndicadoresPca
{
    /**
     * @param  list<array{unidade: string, planejado: bool, tardio: bool, valor: float}>  $processos
     * @param  list<array{unidade: string, situacao: string, data: ?string, valor: float}>  $dfds
     * @return array{total: int, planejados: int, aderencia: float, valor_planejado: float, fora_quantidade: int, fora_valor: float,
     *     atraso_quantidade: int, atraso_valor: float, tardios: int, tardio_indice: float, por_unidade: list<array{unidade: string, planejados: int, total: int, aderencia: float}>}
     */
    public static function calcular(array $processos, array $dfds, string $hoje): array
    {
        $total = count($processos);
        $planejados = array_values(array_filter($processos, fn ($p) => $p['planejado']));
        $fora = array_values(array_filter($processos, fn ($p) => ! $p['planejado']));
        $tardios = array_values(array_filter($processos, fn ($p) => ! $p['planejado'] || $p['tardio']));
        // DFD aprovado ou já no plano que passou da data pretendida sem virar processo.
        $atraso = array_values(array_filter($dfds, fn ($d) => in_array($d['situacao'], ['aceita', 'consolidada'], true) && $d['data'] !== null && $d['data'] < $hoje));

        $unidades = [];
        foreach ($processos as $p) {
            $u = $p['unidade'];
            $unidades[$u] ??= ['unidade' => $u, 'planejados' => 0, 'total' => 0, 'aderencia' => 0.0];
            $unidades[$u]['total']++;
            $unidades[$u]['planejados'] += $p['planejado'] ? 1 : 0;
        }
        foreach ($unidades as &$l) {
            $l['aderencia'] = self::pct($l['planejados'], $l['total']);
        }
        unset($l);
        ksort($unidades);

        return [
            'total' => $total,
            'planejados' => count($planejados),
            'aderencia' => self::pct(count($planejados), $total),
            'valor_planejado' => self::soma($planejados),
            'fora_quantidade' => count($fora),
            'fora_valor' => self::soma($fora),
            'atraso_quantidade' => count($atraso),
            'atraso_valor' => self::soma($atraso),
            'tardios' => count($tardios),
            'tardio_indice' => self::pct(count($tardios), $total),
            'por_unidade' => array_values($unidades),
        ];
    }

    private static function pct(int $parte, int $todo): float
    {
        return $todo === 0 ? 0.0 : round(100 * $parte / $todo, 2);
    }

    private static function soma(array $linhas): float
    {
        return round(array_sum(array_map(fn ($l) => (float) $l['valor'], $linhas)), 2);
    }

    /**
     * Valores do plano por unidade e categoria, para o gráfico da visão geral.
     *
     * @param  list<array{unidade: string, categoria: int, valor: float}>  $itens
     * @return list<array{unidade: string, total: float, categorias: array<int, float>}> da maior para a menor
     */
    public static function valoresPorUnidade(array $itens): array
    {
        $u = [];
        foreach ($itens as $i) {
            $k = $i['unidade'];
            $u[$k] ??= ['unidade' => $k, 'total' => 0.0, 'categorias' => []];
            $u[$k]['total'] = round($u[$k]['total'] + (float) $i['valor'], 2);
            $u[$k]['categorias'][$i['categoria']] = round(($u[$k]['categorias'][$i['categoria']] ?? 0) + (float) $i['valor'], 2);
        }
        usort($u, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp($a['unidade'], $b['unidade']));

        return $u;
    }

    /**
     * Calendário de contratações: quantidade e valor dos itens por mês da data desejada no exercício.
     *
     * @param  list<array{data: ?string, valor: float}>  $itens
     * @return array{meses: array<int, array{quantidade: int, valor: float}>, sem_data: int}
     */
    public static function calendario(int $exercicio, array $itens): array
    {
        $meses = array_fill(1, 12, ['quantidade' => 0, 'valor' => 0.0]);
        $sem = 0;
        foreach ($itens as $i) {
            if ($i['data'] && (int) substr($i['data'], 0, 4) === $exercicio) {
                $m = (int) substr($i['data'], 5, 2);
                $meses[$m]['quantidade']++;
                $meses[$m]['valor'] = round($meses[$m]['valor'] + (float) $i['valor'], 2);
            } else {
                $sem++;
            }
        }

        return ['meses' => $meses, 'sem_data' => $sem];
    }
}
