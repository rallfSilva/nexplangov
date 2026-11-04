<?php

namespace App\Licitacao\Regras;

/**
 * Compara duas versões do Plano de Contratações Anual, item a item, pelo número do item.
 * Depois de aprovado, o PCA só muda por revisão registrada, com justificativa e nova aprovação,
 * e cada versão fica guardada para mostrar o que mudou (art. 12, VII e § 1º, da Lei 14.133;
 * na União, Decreto nº 10.947/2022, arts. 12 e 13).
 */
final class VersoesPca
{
    /** Campos comparados, com o rótulo usado na tela. */
    public const CAMPOS = [
        'descricao' => 'Descrição', 'material_servico' => 'Tipo', 'categoria' => 'Categoria', 'codigo_catalogo' => 'Código CATMAT/CATSER',
        'unidade_fornecimento' => 'Unidade', 'quantidade' => 'Quantidade', 'valor_unitario_estimado' => 'Valor unitário',
        'valor_total_estimado' => 'Valor total', 'unidade_id' => 'Unidade requisitante', 'data_desejada' => 'Data desejada',
        'prioridade' => 'Prioridade', 'natureza_despesa' => 'Natureza da despesa',
    ];

    public const JUSTIFICATIVA_MINIMA = 15;

    /**
     * @param  list<array<string, mixed>>  $antes  itens da versão anterior (cada um com 'numero')
     * @param  list<array<string, mixed>>  $depois  itens da versão nova
     * @return array{incluidos: list<array<string, mixed>>, excluidos: list<array<string, mixed>>, alterados: list<array{numero: int, descricao: string, mudancas: array<string, array{de: mixed, para: mixed}>}>, valor_antes: float, valor_depois: float}
     */
    public static function comparar(array $antes, array $depois): array
    {
        $a = self::porNumero($antes);
        $d = self::porNumero($depois);

        $alterados = [];
        foreach (array_intersect_key($d, $a) as $numero => $novo) {
            $mudancas = [];
            foreach (array_keys(self::CAMPOS) as $campo) {
                $de = self::normal($a[$numero][$campo] ?? null);
                $para = self::normal($novo[$campo] ?? null);
                if ($de !== $para) {
                    $mudancas[$campo] = ['de' => $a[$numero][$campo] ?? null, 'para' => $novo[$campo] ?? null];
                }
            }
            if ($mudancas !== []) {
                $alterados[] = ['numero' => $numero, 'descricao' => (string) ($novo['descricao'] ?? ''), 'mudancas' => $mudancas];
            }
        }

        return [
            'incluidos' => array_values(array_diff_key($d, $a)),
            'excluidos' => array_values(array_diff_key($a, $d)),
            'alterados' => $alterados,
            'valor_antes' => self::total($antes),
            'valor_depois' => self::total($depois),
        ];
    }

    /** @param  list<array<string, mixed>>  $itens */
    public static function total(array $itens): float
    {
        return round(array_sum(array_map(fn ($i) => (float) ($i['valor_total_estimado'] ?? 0), $itens)), 2);
    }

    /**
     * Próximo número de item: maior número já usado no plano atual ou em qualquer versão, mais um.
     * Número de item excluído não volta, para o histórico e o PNCP não confundirem itens diferentes.
     *
     * @param  list<int>  $numerosEmUso
     */
    public static function proximoNumero(array $numerosEmUso): int
    {
        return ($numerosEmUso === [] ? 0 : max($numerosEmUso)) + 1;
    }

    /** Justificativa da revisão: obrigatória e com conteúdo mínimo. */
    public static function justificativaValida(?string $texto): bool
    {
        return mb_strlen(trim((string) $texto)) >= self::JUSTIFICATIVA_MINIMA;
    }

    /**
     * @param  list<array<string, mixed>>  $itens
     * @return array<int, array<string, mixed>>
     */
    private static function porNumero(array $itens): array
    {
        $saida = [];
        foreach ($itens as $i) {
            $saida[(int) $i['numero']] = $i;
        }
        ksort($saida);

        return $saida;
    }

    private static function normal(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        }

        return trim((string) $v);
    }
}
