<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Prazo para as unidades requisitantes enviarem o DFD que comporá o PCA do exercício (art. 12, VII, da Lei 14.133).
 * Depois da data-limite, novas demandas só entram nas exceções que não dependem do planejamento anual, com
 * justificativa.
 */
final class PrazoDemanda
{
    public const EXCECOES = [
        'sigilosa' => 'Contratação com informação sigilosa (art. 13, caput)',
        'suprimento_fundos' => 'Despesa por suprimento de fundos (Lei 4.320/1964, art. 68)',
        'pronto_pagamento' => 'Pequena compra ou serviço de pronto pagamento (art. 95, § 2º)',
    ];

    public const JUSTIFICATIVA_MINIMA = 30;

    /** @return list<string> motivos que impedem enviar a demanda; vazio libera */
    public static function motivos(?CarbonInterface $limite, CarbonInterface $hoje, int $exercicio, ?string $excecao, ?string $justificativa): array
    {
        if ($excecao !== null && $excecao !== '' && ! array_key_exists($excecao, self::EXCECOES)) {
            return ['Exceção ao prazo inválida.'];
        }

        if (! self::encerrado($limite, $hoje)) {
            return [];
        }

        if (! $excecao) {
            return [sprintf(
                'O prazo para enviar demandas para o PCA de %d terminou em %s. Depois dele, só entram as exceções (contratação sigilosa, suprimento de fundos ou pronto pagamento), com justificativa (art. 12, VII).',
                $exercicio, CarbonImmutable::parse($limite)->format('d/m/Y'),
            )];
        }

        if (mb_strlen(trim((string) $justificativa)) < self::JUSTIFICATIVA_MINIMA) {
            return ['Explique por que a demanda se enquadra na exceção, com pelo menos '.self::JUSTIFICATIVA_MINIMA.' caracteres.'];
        }

        return [];
    }

    /** O prazo vale até o fim do dia-limite. */
    public static function encerrado(?CarbonInterface $limite, CarbonInterface $hoje): bool
    {
        return $limite !== null && CarbonImmutable::parse($hoje)->startOfDay()->greaterThan(CarbonImmutable::parse($limite)->startOfDay());
    }
}
