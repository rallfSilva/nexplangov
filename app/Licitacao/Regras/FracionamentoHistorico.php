<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;

/**
 * Compras recorrentes por dispensa de valor nos últimos 12 meses (art. 75, § 1º). O limite do exercício já é
 * controlado em LimiteDispensa; aqui o alerta é de planejamento: o mesmo objeto comprado várias vezes por dispensa,
 * inclusive em exercícios diferentes, indica demanda previsível que deveria estar no PCA e ser licitada ou
 * registrada em ata (art. 12, VII, e art. 40, I).
 */
final class FracionamentoHistorico
{
    public const JANELA_MESES = 12;

    public const MINIMO_COMPRAS = 3;

    /**
     * @param  list<array{processo: string, data: string, natureza: ?string, codigo: ?string, descricao?: ?string, valor: float}>  $compras  dispensas por valor do órgão
     * @return list<array{chave: string, rotulo: string, compras: int, meses: int, valor: float, processos: list<string>, motivo: string, grave: bool}>
     */
    public static function alertas(array $compras, CarbonImmutable $hoje, float $limiteDispensa): array
    {
        $inicio = $hoje->subMonths(self::JANELA_MESES)->toDateString();
        $janela = array_values(array_filter($compras, fn ($c) => $c['data'] >= $inicio && $c['data'] <= $hoje->toDateString()));

        $grupos = [];
        foreach ($janela as $c) {
            $chave = $c['codigo'] ? 'codigo:'.$c['codigo'] : ($c['natureza'] ? 'natureza:'.$c['natureza'] : null);
            if ($chave) {
                $grupos[$chave][] = $c;
            }
        }

        $r = [];
        foreach ($grupos as $chave => $g) {
            $processos = array_values(array_unique(array_column($g, 'processo')));
            $valor = round(array_sum(array_column($g, 'valor')), 2);
            $meses = count(array_unique(array_map(fn ($c) => substr($c['data'], 0, 7), $g)));
            $passa = $limiteDispensa > 0 && $valor > $limiteDispensa;
            if (count($processos) < self::MINIMO_COMPRAS && ! $passa) {
                continue;
            }
            [$tipo, $valorChave] = explode(':', $chave, 2);
            $r[] = [
                'chave' => $chave, 'rotulo' => ($tipo === 'codigo' ? 'Código ' : 'Natureza ').$valorChave, 'compras' => count($processos), 'meses' => $meses, 'valor' => $valor,
                'processos' => $processos, 'grave' => $passa,
                'motivo' => sprintf('%d dispensas em %d meses somando R$ %s%s. Demanda recorrente: inclua no PCA e contrate por licitação ou registro de preços (art. 12, VII; art. 75, § 1º).',
                    count($processos), $meses, number_format($valor, 2, ',', '.'), $passa ? ', acima do limite anual de dispensa (R$ '.number_format($limiteDispensa, 2, ',', '.').')' : ''),
            ];
        }
        usort($r, fn ($a, $b) => [$b['grave'], $b['valor']] <=> [$a['grave'], $a['valor']]);

        return $r;
    }
}
