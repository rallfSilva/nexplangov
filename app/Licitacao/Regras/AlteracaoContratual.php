<?php

namespace App\Licitacao\Regras;

/**
 * Limites de acréscimo e supressão unilaterais (art. 125 da Lei 14.133): até 25% do valor inicial
 * atualizado do contrato, ou até 50% nos acréscimos de reforma de edifício ou de equipamento.
 * Acréscimos e supressões são somados separadamente, sem compensação entre si.
 */
final class AlteracaoContratual
{
    /**
     * @param  float  $valorInicialAtualizado  valor inicial mais reajustes e repactuações apostilados
     * @return array{limite_acrescimo: float, limite_supressao: float, percentual_acrescimo: float, percentual_supressao: float, permitido: bool, mensagens: list<string>}
     */
    public function avaliar(float $valorInicialAtualizado, float $acrescimosAnteriores, float $supressoesAnteriores, float $novoAcrescimo, float $novaSupressao, bool $reforma = false): array
    {
        $pctAcrescimo = $reforma ? 0.50 : 0.25;
        $limiteAcrescimo = round($valorInicialAtualizado * $pctAcrescimo, 2);
        $limiteSupressao = round($valorInicialAtualizado * 0.25, 2);

        $acrescimos = round($acrescimosAnteriores + $novoAcrescimo, 2);
        $supressoes = round($supressoesAnteriores + $novaSupressao, 2);

        $mensagens = [];

        if ($acrescimos > $limiteAcrescimo) {
            $mensagens[] = sprintf('Acréscimos somam %s%% do valor inicial atualizado. O limite é %d%% (art. 125).', $this->pct($acrescimos, $valorInicialAtualizado), $pctAcrescimo * 100);
        }

        if ($supressoes > $limiteSupressao) {
            $mensagens[] = sprintf('Supressões somam %s%% do valor inicial atualizado. O limite unilateral é 25%% (art. 125). Acima disso, só por acordo entre as partes (art. 124, II).', $this->pct($supressoes, $valorInicialAtualizado));
        }

        return [
            'limite_acrescimo' => $limiteAcrescimo,
            'limite_supressao' => $limiteSupressao,
            'percentual_acrescimo' => $valorInicialAtualizado > 0 ? round($acrescimos / $valorInicialAtualizado * 100, 2) : 0.0,
            'percentual_supressao' => $valorInicialAtualizado > 0 ? round($supressoes / $valorInicialAtualizado * 100, 2) : 0.0,
            'permitido' => $mensagens === [],
            'mensagens' => $mensagens,
        ];
    }

    private function pct(float $parte, float $todo): string
    {
        return $todo > 0 ? number_format($parte / $todo * 100, 2, ',', '.') : '0';
    }
}
