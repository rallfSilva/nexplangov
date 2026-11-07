<?php

namespace App\Licitacao\Regras;

/**
 * Sugestão de agrupamento dos itens do PCA (art. 12, VII, e art. 40, V, a e b): o mesmo objeto pedido por mais
 * de uma unidade, ou repetido no plano, deve ir a uma só contratação, dividida em itens ou lotes quando o
 * parcelamento for viável (art. 40, § 2º). Evita fracionar a despesa (art. 75, § 1º) e ganha escala.
 * Agrupa pelo código CATMAT/CATSER, depois pela classe do catálogo e, sem código, por descrição parecida.
 */
final class ConsolidacaoPca
{
    /**
     * @param  list<array{id: int, numero: int, unidade: ?string, tipo: ?string, codigo: ?string, classe: ?string, classe_nome?: ?string, natureza: ?string, descricao: string, valor: float, data: ?string}>  $itens
     * @return list<array{criterio: string, chave: string, rotulo: string, itens: list<int>, numeros: list<int>, unidades: list<string>, valor: float, data: ?string, motivo: string}>
     */
    public static function sugestoes(array $itens): array
    {
        $grupos = [];
        $usados = [];

        // 1. Mesmo código do catálogo nacional.
        foreach (self::agrupar($itens, fn ($i) => $i['codigo'] ? ($i['tipo'] ?? '').':'.$i['codigo'] : null) as $chave => $g) {
            $grupos[] = self::grupo('codigo', $chave, 'Código '.explode(':', $chave)[1], $g, 'mesmo código do catálogo');
            $usados = [...$usados, ...array_column($g, 'id')];
        }

        // 2. Mesma classe do catálogo (itens diferentes do mesmo mercado), entre os que sobraram.
        $resto = array_values(array_filter($itens, fn ($i) => ! in_array($i['id'], $usados, true)));
        foreach (self::agrupar($resto, fn ($i) => $i['classe'] ? ($i['tipo'] ?? '').':'.$i['classe'] : null) as $chave => $g) {
            $grupos[] = self::grupo('classe', $chave, 'Classe '.($g[0]['classe_nome'] ?? explode(':', $chave)[1]), $g, 'mesma classe do catálogo (mesmo mercado fornecedor)');
            $usados = [...$usados, ...array_column($g, 'id')];
        }

        // 3. Sem código: descrição parecida e mesma natureza de despesa.
        $resto = array_values(array_filter($itens, fn ($i) => ! in_array($i['id'], $usados, true) && ! $i['codigo']));
        while ($resto) {
            $base = array_shift($resto);
            $iguais = array_values(array_filter($resto, fn ($i) => $i['natureza'] === $base['natureza'] && DescricaoCatalogo::semelhanca($i['descricao'], $base['descricao']) >= DescricaoCatalogo::LIMIAR_SEMELHANCA));
            if ($iguais) {
                $grupos[] = self::grupo('descricao', 'desc:'.$base['id'], mb_strimwidth($base['descricao'], 0, 60, '…'), [$base, ...$iguais], 'descrição parecida e mesma natureza de despesa');
                $ids = array_column($iguais, 'id');
                $resto = array_values(array_filter($resto, fn ($i) => ! in_array($i['id'], $ids, true)));
            }
        }

        usort($grupos, fn ($a, $b) => $b['valor'] <=> $a['valor']);

        return $grupos;
    }

    /** @return array<string, list<array<string, mixed>>> só os grupos com dois ou mais itens */
    private static function agrupar(array $itens, callable $chave): array
    {
        $g = [];
        foreach ($itens as $i) {
            if (($k = $chave($i)) !== null) {
                $g[$k][] = $i;
            }
        }

        return array_filter($g, fn ($x) => count($x) >= 2);
    }

    private static function grupo(string $criterio, string $chave, string $rotulo, array $g, string $porque): array
    {
        $unidades = array_values(array_unique(array_filter(array_column($g, 'unidade'))));
        $datas = array_filter(array_column($g, 'data'));
        sort($datas);
        $valor = round(array_sum(array_column($g, 'valor')), 2);

        return [
            'criterio' => $criterio, 'chave' => $chave, 'rotulo' => $rotulo, 'itens' => array_column($g, 'id'), 'numeros' => array_column($g, 'numero'),
            'unidades' => $unidades, 'valor' => $valor, 'data' => $datas[0] ?? null,
            'motivo' => sprintf('%d itens com %s%s: reúna em uma contratação, com itens ou lotes se o parcelamento for vantajoso (art. 40, V, e § 2º), para ganhar escala e não fracionar a despesa (art. 75, § 1º).',
                count($g), $porque, count($unidades) > 1 ? ', pedidos por '.implode(', ', $unidades) : ''),
        ];
    }
}
