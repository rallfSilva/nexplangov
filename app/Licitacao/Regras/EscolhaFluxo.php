<?php

namespace App\Licitacao\Regras;

/**
 * Escolhe o fluxo do processo pelo critério mais específico que o órgão cadastrou: secretaria (unidade que abre o
 * processo), tipo de processo (modalidade), categoria e forma (licitação ou contratação direta). Cada órgão organiza
 * a própria instrução (art. 7º e art. 8º, § 3º, da Lei 14.133), e o fluxo é o modo de fazer isso no sistema.
 *
 * Regra pura.
 */
final class EscolhaFluxo
{
    /**
     * Pontua o fluxo para o processo; null quando o fluxo não serve. Peso: secretaria 8, modalidade 4, categoria 2,
     * forma específica 1. Assim, o fluxo da secretaria vence o da modalidade, que vence o da categoria.
     *
     * @param  array{aplicacao: string, categoria: ?int, modalidade: ?int, unidade_id: ?int}  $fluxo
     * @param  array{direta: bool, categoria: ?int, modalidade: ?int, unidade_id: ?int}  $processo
     */
    public static function pontuar(array $fluxo, array $processo): ?int
    {
        $forma = $processo['direta'] ? 'direta' : 'licitacao';

        if (! in_array($fluxo['aplicacao'], ['todos', $forma], true)) {
            return null;
        }

        $pontos = $fluxo['aplicacao'] !== 'todos' ? 1 : 0;

        foreach (['categoria' => 2, 'modalidade' => 4, 'unidade_id' => 8] as $campo => $peso) {
            if ($fluxo[$campo] === null) {
                continue;
            }
            if ((int) $fluxo[$campo] !== (int) $processo[$campo]) {
                return null;
            }
            $pontos += $peso;
        }

        return $pontos;
    }

    /**
     * Índice do fluxo escolhido entre os candidatos (o de maior pontuação; no empate, o primeiro), ou null.
     *
     * @param  list<array{aplicacao: string, categoria: ?int, modalidade: ?int, unidade_id: ?int}>  $fluxos
     */
    public static function escolher(array $fluxos, array $processo): ?int
    {
        $melhor = null;
        $pontos = -1;
        foreach ($fluxos as $i => $f) {
            $p = self::pontuar($f, $processo);
            if ($p !== null && $p > $pontos) {
                [$melhor, $pontos] = [$i, $p];
            }
        }

        return $melhor;
    }

    /** Próxima unidade do roteiro a partir de onde o processo está. Fora do roteiro, sugere a primeira. @param list<int> $roteiro */
    public static function proximaUnidade(array $roteiro, ?int $atual): ?int
    {
        $roteiro = array_values(array_map('intval', $roteiro));
        if ($roteiro === []) {
            return null;
        }

        $pos = $atual === null ? false : array_search($atual, $roteiro, true);
        if ($pos === false) {
            return $roteiro[0] !== $atual ? $roteiro[0] : ($roteiro[1] ?? null);
        }

        return $roteiro[$pos + 1] ?? null;
    }
}
