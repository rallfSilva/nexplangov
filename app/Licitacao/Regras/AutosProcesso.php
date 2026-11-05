<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Autos do processo: tudo o que foi formalmente produzido ou juntado, em ordem cronológica e numerado, como as folhas
 * do processo em papel (art. 12, I, da Lei 14.133: atos escritos, com data e identificação do responsável; e art. 6º,
 * LVIII, o processo como conjunto de documentos). A árvore agrupa os documentos por fase sem perder a numeração única.
 *
 * Regra pura: recebe os documentos já apurados.
 */
final class AutosProcesso
{
    public const FASES = [
        'instrucao' => 'Fase preparatória',
        'juridico' => 'Análise jurídica',
        'autorizacao' => 'Autorização',
        'selecao' => 'Seleção do fornecedor',
        'contratacao' => 'Contratação',
        'juntada' => 'Documentos juntados',
    ];

    public const JUSTIFICATIVA_MINIMA = 15;

    /**
     * Numera os documentos pela data de entrada nos autos (empate: pela ordem recebida) e agrupa por fase.
     *
     * @param  list<array{data: CarbonInterface, fase: string, titulo: string}&array<string, mixed>>  $documentos
     * @return array{itens: list<array<string, mixed>>, arvore: array<string, list<array<string, mixed>>>}
     */
    public static function montar(array $documentos): array
    {
        $ordenados = array_values($documentos);
        $indices = array_keys($ordenados);
        usort($indices, function (int $a, int $b) use ($ordenados) {
            $cmp = CarbonImmutable::parse($ordenados[$a]['data'])->getTimestamp() <=> CarbonImmutable::parse($ordenados[$b]['data'])->getTimestamp();

            return $cmp !== 0 ? $cmp : $a <=> $b;
        });

        $itens = [];
        foreach ($indices as $n => $i) {
            $itens[] = $ordenados[$i] + ['numero' => $n + 1];
        }

        $arvore = [];
        foreach (array_keys(self::FASES) as $fase) {
            $nela = array_values(array_filter($itens, fn ($d) => $d['fase'] === $fase));
            if ($nela !== []) {
                $arvore[$fase] = $nela;
            }
        }

        return ['itens' => $itens, 'arvore' => $arvore];
    }

    /** Fase de cada peça do processo, pela etapa do checklist. */
    public static function faseDaPeca(string $tipo): string
    {
        return match ($tipo) {
            'parecer_juridico' => 'juridico',
            'autorizacao' => 'autorizacao',
            'habilitacao', 'razao_escolha', 'justificativa_preco' => 'instrucao',
            default => 'instrucao',
        };
    }

    /** @return list<string> */
    public static function motivosDesentranhamento(bool $jaDesentranhado, ?string $motivo): array
    {
        $m = [];
        if ($jaDesentranhado) {
            $m[] = 'Este documento já foi desentranhado.';
        }
        if (mb_strlen(trim((string) $motivo)) < self::JUSTIFICATIVA_MINIMA) {
            $m[] = 'Explique por que o documento sai dos autos, com pelo menos '.self::JUSTIFICATIVA_MINIMA.' caracteres. O registro da juntada continua no histórico.';
        }

        return $m;
    }

    /** Conta as páginas de um PDF pelo dicionário de páginas. Aproximado: serve para o índice, não para validar. */
    public static function paginasPdf(string $conteudo): ?int
    {
        if (! str_starts_with($conteudo, '%PDF')) {
            return null;
        }

        $n = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $conteudo);

        return $n > 0 ? $n : null;
    }
}
