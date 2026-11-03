<?php

namespace App\Licitacao\Regras;

use App\Licitacao\Enums\CriterioJulgamento;

/**
 * Regras puras do julgamento das propostas: classificação pelo critério (art. 33), lances,
 * desclassificação por preço (art. 59) e empate (art. 60 e tratamento da ME/EPP, art. 4º).
 */
final class Julgamento
{
    /** Empate ficto da ME/EPP: até 10% acima da melhor proposta; no pregão, até 5% (LC 123/2006, arts. 44 e 45, aplicada pelo art. 4º). */
    public const LIMITE_EMPATE_FICTO = 0.10;

    public const LIMITE_EMPATE_FICTO_PREGAO = 0.05;

    /** Obras e serviços de engenharia: abaixo de 75% do orçado é inexequível (art. 59, § 4º). */
    public const PISO_ENGENHARIA = 0.75;

    /** Ordem do desempate (art. 60). */
    public const DESEMPATE = [
        'disputa_final' => 'Disputa final, com nova proposta em ato contínuo à classificação (art. 60, I)',
        'desempenho_contratual' => 'Avaliação do desempenho contratual prévio (art. 60, II)',
        'equidade' => 'Ações de equidade entre homens e mulheres no ambiente de trabalho (art. 60, III)',
        'integridade' => 'Programa de integridade (art. 60, IV)',
    ];

    /** Quanto menor o número, melhor. No maior desconto e no maior lance, o valor maior vence. */
    public function maiorEhMelhor(CriterioJulgamento $criterio): bool
    {
        return in_array($criterio, [CriterioJulgamento::MaiorDesconto, CriterioJulgamento::MaiorLance, CriterioJulgamento::MaiorRetornoEconomico], true);
    }

    /**
     * Ordena as propostas válidas da melhor para a pior. Empatadas ficam na ordem de chegada (id).
     *
     * @param  list<array{id: int, valor: float, situacao: string}>  $propostas
     * @return list<int> ids
     */
    public function classificar(array $propostas, CriterioJulgamento $criterio): array
    {
        $validas = array_values(array_filter($propostas, fn ($p) => $p['situacao'] === 'classificada'));
        $maior = $this->maiorEhMelhor($criterio);

        usort($validas, fn ($a, $b) => $this->cmp($a['valor'], $b['valor'], $maior) ?: $a['id'] <=> $b['id']);

        return array_column($validas, 'id');
    }

    /**
     * Propostas empatadas com a primeira (mesmo valor).
     *
     * @param  list<array{id: int, valor: float, situacao: string}>  $propostas
     * @return list<int>
     */
    public function empatadas(array $propostas, CriterioJulgamento $criterio): array
    {
        $ordem = $this->classificar($propostas, $criterio);

        if ($ordem === []) {
            return [];
        }

        $porId = array_column($propostas, null, 'id');
        $melhor = round($porId[$ordem[0]]['valor'], 4);

        return array_values(array_filter($ordem, fn ($id) => round($porId[$id]['valor'], 4) === $melhor));
    }

    /**
     * ME/EPP com direito de cobrir a melhor proposta (empate ficto). Vale só no menor preço e quando
     * a melhor proposta não é de ME/EPP.
     *
     * @param  list<array{id: int, valor: float, situacao: string, me_epp: bool}>  $propostas
     * @return list<int> ids de ME/EPP, na ordem de classificação
     */
    public function empateFicto(array $propostas, CriterioJulgamento $criterio, bool $pregao): array
    {
        if ($criterio !== CriterioJulgamento::MenorPreco) {
            return [];
        }

        $ordem = $this->classificar($propostas, $criterio);
        $porId = array_column($propostas, null, 'id');

        if ($ordem === [] || $porId[$ordem[0]]['me_epp']) {
            return [];
        }

        $teto = $porId[$ordem[0]]['valor'] * (1 + ($pregao ? self::LIMITE_EMPATE_FICTO_PREGAO : self::LIMITE_EMPATE_FICTO));

        return array_values(array_filter($ordem, fn ($id) => $porId[$id]['me_epp'] && round($porId[$id]['valor'], 2) <= round($teto, 2)));
    }

    /** O novo lance precisa ser melhor que o melhor vigente, descontado o intervalo mínimo. */
    public function lanceValido(float $lance, ?float $melhorVigente, float $intervaloMinimo, CriterioJulgamento $criterio): bool
    {
        if ($lance <= 0) {
            return false;
        }

        if ($melhorVigente === null) {
            return true;
        }

        return $this->maiorEhMelhor($criterio)
            ? $lance >= $melhorVigente + $intervaloMinimo + 0.00001 && $lance > $melhorVigente
            : $lance <= $melhorVigente - $intervaloMinimo - 0.00001 && $lance < $melhorVigente;
    }

    /** Preço acima do orçamento estimado é causa de desclassificação (art. 59, III). */
    public function acimaDoOrcamento(float $valor, float $estimado): bool
    {
        return $estimado > 0 && round($valor, 4) > round($estimado, 4);
    }

    /** Obras e serviços de engenharia: abaixo de 75% do orçado, inexequível (art. 59, § 4º). */
    public function inexequivelEngenharia(float $valor, float $estimado): bool
    {
        return $estimado > 0 && $valor < $estimado * self::PISO_ENGENHARIA;
    }

    private function cmp(float $a, float $b, bool $maior): int
    {
        return $maior ? $b <=> $a : $a <=> $b;
    }
}
