<?php

namespace App\Licitacao\Regras;

/**
 * Cronograma do PCA com o orçamento: quanto o plano prevê contratar em cada mês e se a dotação de cada natureza de
 * despesa cobre o planejado. O planejamento precisa ser compatível com as leis orçamentárias (art. 18, caput) e a
 * contratação só começa com a indicação do crédito (art. 150).
 */
final class CronogramaPca
{
    /**
     * @param  list<array{natureza: ?string, valor: float, data: ?string}>  $itens
     * @param  array<string, float>  $dotacoes  natureza => valor autorizado no exercício
     * @return array{meses: array<int, float>, sem_data: float, naturezas: list<array{natureza: string, planejado: float, dotado: ?float, saldo: ?float, situacao: string}>, total: float, total_dotado: float, alertas: list<string>}
     */
    public static function montar(int $exercicio, array $itens, array $dotacoes): array
    {
        $meses = array_fill(1, 12, 0.0);
        $semData = 0.0;
        $porNatureza = [];
        foreach ($itens as $i) {
            $v = round((float) $i['valor'], 2);
            if ($i['data'] && (int) substr($i['data'], 0, 4) === $exercicio) {
                $meses[(int) substr($i['data'], 5, 2)] += $v;
            } else {
                $semData += $v;
            }
            $n = $i['natureza'] ?: 'sem natureza';
            $porNatureza[$n] = ($porNatureza[$n] ?? 0) + $v;
        }

        $naturezas = [];
        $alertas = [];
        foreach ($porNatureza + array_fill_keys(array_keys($dotacoes), 0.0) as $n => $planejado) {
            $planejado = round($planejado, 2);
            $dotado = array_key_exists($n, $dotacoes) ? round((float) $dotacoes[$n], 2) : null;
            $saldo = $dotado === null ? null : round($dotado - $planejado, 2);
            $situacao = match (true) {
                $n === 'sem natureza' => 'sem_natureza',
                $dotado === null => 'sem_dotacao',
                $saldo < 0 => 'insuficiente',
                default => 'coberto',
            };
            if ($situacao === 'sem_dotacao' && $planejado > 0) {
                $alertas[] = sprintf('Natureza %s: R$ %s planejados sem dotação cadastrada (art. 18, caput).', $n, self::reais($planejado));
            } elseif ($situacao === 'insuficiente') {
                $alertas[] = sprintf('Natureza %s: o planejado passa a dotação em R$ %s. Reveja o plano ou peça suplementação antes de contratar (art. 150).', $n, self::reais(-$saldo));
            } elseif ($situacao === 'sem_natureza') {
                $alertas[] = sprintf('R$ %s em itens sem natureza de despesa: informe-a para conferir o orçamento.', self::reais($planejado));
            }
            $naturezas[] = ['natureza' => $n, 'planejado' => $planejado, 'dotado' => $dotado, 'saldo' => $saldo, 'situacao' => $situacao];
        }
        usort($naturezas, fn ($a, $b) => strcmp($a['natureza'], $b['natureza']));
        if ($semData > 0) {
            $alertas[] = sprintf('R$ %s em itens sem data desejada dentro de %d: informe o mês para montar o cronograma.', self::reais($semData), $exercicio);
        }

        return [
            'meses' => array_map(fn ($v) => round($v, 2), $meses), 'sem_data' => round($semData, 2), 'naturezas' => $naturezas,
            'total' => round(array_sum($porNatureza), 2), 'total_dotado' => round(array_sum($dotacoes), 2), 'alertas' => $alertas,
        ];
    }

    private static function reais(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }
}
