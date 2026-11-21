<?php

namespace App\Licitacao\Regras;

/**
 * Consolidação dos DFDs aprovados nos itens do PCA (art. 12, VII). Cada unidade tem as suas linhas no plano:
 * o mesmo item do catálogo (CATMAT/CATSER), na mesma unidade de fornecimento, pedido em vários DFDs da mesma
 * unidade vira uma só linha, com a soma das quantidades e o valor unitário médio ponderado. Item sem código
 * só se junta a outro de descrição igual. A linha já existente no plano recebe as novas quantidades.
 */
final class ConsolidacaoDfd
{
    /**
     * @param  list<array{id: int, unidade_id: ?int, material_servico: string, codigo: ?string, descricao: string, unidade_fornecimento: string, quantidade: float, valor_unitario: float, categoria: int, data: ?string, prioridade: int}>  $itensDfd
     * @param  list<array{id: int, unidade_id: ?int, material_servico: string, codigo: ?string, descricao: string, unidade_fornecimento: string, quantidade: float, valor_unitario: float, data: ?string, prioridade: int}>  $itensPca
     * @return list<array{item_pca_id: ?int, itens_dfd: list<int>, unidade_id: ?int, material_servico: string, codigo: ?string, descricao: string, unidade_fornecimento: string, categoria: ?int, quantidade: float, valor_unitario: float, data: ?string, prioridade: int}>
     */
    public static function planejar(array $itensDfd, array $itensPca): array
    {
        $linhas = [];

        foreach ($itensPca as $p) {
            $chave = self::chave($p);
            if (! isset($linhas[$chave])) {
                $linhas[$chave] = ['item_pca_id' => $p['id'], 'itens_dfd' => [], 'unidade_id' => $p['unidade_id'], 'material_servico' => $p['material_servico'],
                    'codigo' => $p['codigo'] ?: null, 'descricao' => $p['descricao'], 'unidade_fornecimento' => $p['unidade_fornecimento'], 'categoria' => null,
                    'quantidade' => (float) $p['quantidade'], 'valor_unitario' => (float) $p['valor_unitario'], 'data' => $p['data'], 'prioridade' => (int) $p['prioridade']];
            }
        }

        foreach ($itensDfd as $d) {
            $chave = self::chave($d);
            if (! isset($linhas[$chave])) {
                $linhas[$chave] = ['item_pca_id' => null, 'itens_dfd' => [], 'unidade_id' => $d['unidade_id'], 'material_servico' => $d['material_servico'],
                    'codigo' => $d['codigo'] ?: null, 'descricao' => $d['descricao'], 'unidade_fornecimento' => $d['unidade_fornecimento'], 'categoria' => (int) $d['categoria'],
                    'quantidade' => 0.0, 'valor_unitario' => 0.0, 'data' => null, 'prioridade' => 3];
            }
            $l = &$linhas[$chave];
            $total = $l['quantidade'] * $l['valor_unitario'] + (float) $d['quantidade'] * (float) $d['valor_unitario'];
            $l['quantidade'] = round($l['quantidade'] + (float) $d['quantidade'], 4);
            $l['valor_unitario'] = $l['quantidade'] > 0 ? round($total / $l['quantidade'], 4) : 0.0;
            $l['data'] = self::menorData($l['data'], $d['data']);
            $l['prioridade'] = min($l['prioridade'], (int) $d['prioridade']);
            $l['itens_dfd'][] = $d['id'];
            unset($l);
        }

        // Só as linhas que recebem algum item de DFD mudam.
        return array_values(array_filter($linhas, fn ($l) => $l['itens_dfd'] !== []));
    }

    /**
     * Unidade em que o DFD é consolidado. Por unidade, o setor sobe até a unidade participante que o contém
     * (no plano do órgão todo, até a unidade de topo). Por setor, cada setor fica com as suas linhas.
     * Devolve null quando a unidade não pertence a nenhuma participante.
     *
     * @param  array<int, ?int>  $pais  id da unidade => id da superior
     * @param  list<int>  $participantes  vazio no plano do órgão todo
     */
    public static function unidadeConsolidadora(array $pais, array $participantes, ?int $unidade, string $tipo): ?int
    {
        if ($unidade === null) {
            return null;
        }
        $participante = self::participanteDe($pais, $participantes, $unidade);
        if ($participantes !== [] && $participante === null) {
            return null;
        }
        if ($tipo === 'setor') {
            return $unidade;
        }

        return $participante ?? self::raiz($pais, $unidade);
    }

    /** A própria unidade ou a superior mais próxima que participa do plano. Plano do órgão todo: a raiz. */
    public static function participanteDe(array $pais, array $participantes, int $unidade): ?int
    {
        if ($participantes === []) {
            return self::raiz($pais, $unidade);
        }
        $visitadas = [];
        for ($u = $unidade; $u !== null && ! isset($visitadas[$u]); $u = $pais[$u] ?? null) {
            if (in_array($u, $participantes, true)) {
                return $u;
            }
            $visitadas[$u] = true;
        }

        return null;
    }

    private static function raiz(array $pais, int $unidade): int
    {
        $visitadas = [];
        while (($pais[$unidade] ?? null) !== null && ! isset($visitadas[$unidade])) {
            $visitadas[$unidade] = true;
            $unidade = $pais[$unidade];
        }

        return $unidade;
    }

    /** Mesma unidade, mesmo tipo e mesma unidade de fornecimento; depois o código do catálogo ou, sem ele, a descrição. */
    public static function chave(array $i): string
    {
        $base = ($i['unidade_id'] ?? 0).'|'.$i['material_servico'].'|'.mb_strtoupper(trim($i['unidade_fornecimento'])).'|';
        $codigo = trim((string) ($i['codigo'] ?? ''));

        return $codigo !== '' ? $base.'c:'.$codigo : $base.'d:'.self::normalizar($i['descricao']);
    }

    private static function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto));
        $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);

        return (string) preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $t));
    }

    private static function menorData(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return min($a, $b);
    }

    /**
     * Progresso por unidade: DFDs aprovados ou já consolidados e quantos estão no plano.
     *
     * @param  list<array{unidade: string, situacao: string, valor: float}>  $demandas
     * @return list<array{unidade: string, total: int, consolidados: int, valor: float, percentual: int}>
     */
    public static function progresso(array $demandas): array
    {
        $p = [];
        foreach ($demandas as $d) {
            $u = $d['unidade'];
            $p[$u] ??= ['unidade' => $u, 'total' => 0, 'consolidados' => 0, 'valor' => 0.0, 'percentual' => 0];
            $p[$u]['total']++;
            $p[$u]['valor'] = round($p[$u]['valor'] + (float) $d['valor'], 2);
            if ($d['situacao'] === 'consolidada') {
                $p[$u]['consolidados']++;
            }
        }

        foreach ($p as &$linha) {
            $linha['percentual'] = (int) floor(100 * $linha['consolidados'] / $linha['total']);
        }
        unset($linha);
        ksort($p);

        return array_values($p);
    }
}
