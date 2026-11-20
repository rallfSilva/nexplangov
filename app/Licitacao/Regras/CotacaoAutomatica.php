<?php

namespace App\Licitacao\Regras;

use Illuminate\Support\Str;

/**
 * Regras puras da cotação automática por IA (art. 23, § 1º, I e II, da Lei 14.133/2021).
 *
 * A IA só classifica: diz qual código do catálogo corresponde ao item e quais preços encontrados são de um objeto
 * equivalente. Quem escolhe os preços que entram, e em que ordem, é esta regra; quem calcula o preço estimado é a
 * PesquisaPrecos. Assim o resultado é reproduzível e auditável, e a resposta da IA nunca vira número sem conferência.
 */
final class CotacaoAutomatica
{
    /** Palavras que não ajudam a achar o item no catálogo nem nas compras publicadas. */
    private const VAZIAS = ['para', 'com', 'sem', 'tipo', 'cor', 'tamanho', 'material', 'unidade', 'aquisicao', 'fornecimento', 'contratacao',
        'servico', 'servicos', 'deve', 'devera', 'conforme', 'minimo', 'maximo', 'aproximadamente', 'medindo', 'medida', 'medidas', 'pacote',
        'caixa', 'embalagem', 'contendo', 'cada', 'pela', 'pelo', 'das', 'dos', 'uma', 'que', 'nao', 'outros', 'demais', 'especificacao'];

    /**
     * Palavras significativas da descrição, na ordem em que aparecem (a primeira costuma ser o nome do item).
     *
     * @return list<string>
     */
    public static function palavrasChave(string $descricao, int $limite = 6): array
    {
        $texto = Str::lower(Str::ascii($descricao));
        $palavras = preg_split('/[^a-z0-9]+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
        $saida = [];
        foreach ($palavras as $p) {
            $curtaSemCodigo = mb_strlen($p) < 3 && ! (preg_match('/\d/', $p) && preg_match('/[a-z]/', $p)); // "a4" fica
            if ($curtaSemCodigo || ctype_digit($p) || in_array($p, self::VAZIAS, true) || in_array($p, $saida, true)) {
                continue;
            }
            $saida[] = $p;
            if (count($saida) >= $limite) {
                break;
            }
        }

        return $saida;
    }

    /** Quantas das palavras-chave aparecem no texto (0 a 1). Serve para ordenar candidatos antes de chamar a IA. */
    public static function semelhanca(array $palavras, string $texto): float
    {
        if ($palavras === []) {
            return 0.0;
        }
        $texto = Str::lower(Str::ascii($texto));
        $achadas = array_filter($palavras, fn ($p) => str_contains($texto, $p));

        return count($achadas) / count($palavras);
    }

    /**
     * Lê a resposta da IA sobre o código do catálogo. Só aceita um código que estava na lista enviada.
     *
     * @param  list<string>  $permitidos
     * @return array{codigo: ?string, termos: ?string}
     */
    public static function lerCatalogo(string $texto, array $permitidos): array
    {
        $dados = self::json($texto, '{');
        $codigo = isset($dados['codigo']) ? preg_replace('/\D/', '', (string) $dados['codigo']) : null;
        $termos = isset($dados['termos']) && is_string($dados['termos']) ? trim(preg_replace('/\s+/', ' ', $dados['termos'])) : null;

        return [
            'codigo' => $codigo !== null && $codigo !== '' && in_array($codigo, $permitidos, true) ? $codigo : null,
            'termos' => $termos !== null && $termos !== '' ? mb_substr($termos, 0, 80) : null,
        ];
    }

    /**
     * Lê a avaliação da IA sobre os preços encontrados. Índice fora da lista ou sem resposta conta como incompatível.
     *
     * @return array{compativeis: array<int, string>, justificativa: ?string}
     */
    public static function lerAvaliacao(string $texto, int $totalCandidatos): array
    {
        $dados = self::json($texto, '{');
        $compativeis = [];
        foreach (($dados['avaliacoes'] ?? []) as $a) {
            if (! is_array($a) || ! isset($a['i']) || ! is_numeric($a['i'])) {
                continue;
            }
            $i = (int) $a['i'];
            if ($i < 0 || $i >= $totalCandidatos || empty($a['compativel']) || $a['compativel'] === 'false') {
                continue;
            }
            $compativeis[$i] = mb_substr(trim((string) ($a['motivo'] ?? 'Objeto equivalente ao item.')), 0, 500);
        }
        $justificativa = isset($dados['justificativa']) && is_string($dados['justificativa']) ? trim($dados['justificativa']) : null;

        return ['compativeis' => $compativeis, 'justificativa' => $justificativa !== '' ? mb_substr((string) $justificativa, 0, 4000) : null];
    }

    /**
     * Intercala os preços das fontes (mais recente de cada uma, depois o segundo de cada uma...) e corta no limite.
     *
     * @param  list<array{origem: string, data: string}>  $lista
     * @return list<array<string, mixed>>
     */
    public static function intercalar(array $lista, int $limite): array
    {
        $porFonte = [];
        foreach ($lista as $p) {
            $porFonte[$p['origem']][] = $p;
        }
        foreach ($porFonte as &$l) {
            usort($l, fn ($a, $b) => strcmp($b['data'], $a['data']));
        }
        unset($l);

        $saida = [];
        for ($i = 0; count($saida) < $limite; $i++) {
            $achou = false;
            foreach ($porFonte as $l) {
                if (isset($l[$i])) {
                    $saida[] = $l[$i];
                    $achou = true;
                    if (count($saida) >= $limite) {
                        break;
                    }
                }
            }
            if (! $achou) {
                break;
            }
        }

        return $saida;
    }

    /**
     * Escolhe até N preços entre os compatíveis, equilibrando as fontes (Painel de Preços, PNCP, notas fiscais): em cada
     * rodada pega o mais recente ainda não usado de cada fonte, sem repetir a mesma compra ou nota. Não escolhe por
     * valor, para não enviesar a pesquisa para cima ou para baixo.
     *
     * @param  list<array{id: string, valor: float, data: string, origem?: string}>  $candidatos
     * @param  array<int, string>  $compativeis  índice do candidato => motivo da compatibilidade
     * @return list<int> índices escolhidos
     */
    public static function selecionar(array $candidatos, array $compativeis, int $quantidade): array
    {
        $indices = array_keys($compativeis);
        usort($indices, fn ($a, $b) => [$candidatos[$b]['data'] ?? '', $a] <=> [$candidatos[$a]['data'] ?? '', $b]);

        $porFonte = [];
        $vistos = [];
        foreach ($indices as $i) {
            $c = $candidatos[$i] ?? null;
            if (! $c || (float) $c['valor'] <= 0) {
                continue;
            }
            $chave = $c['id'].'|'.round((float) $c['valor'], 2);
            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;
            $porFonte[$c['origem'] ?? ''][] = $i;
        }

        $saida = [];
        while (count($saida) < $quantidade && $porFonte !== []) {
            foreach ($porFonte as $f => &$fila) {
                $saida[] = array_shift($fila);
                if ($fila === []) {
                    unset($porFonte[$f]);
                }
                if (count($saida) >= $quantidade) {
                    break;
                }
            }
            unset($fila);
        }

        return $saida;
    }

    private static function json(string $texto, string $abre): array
    {
        $fecha = $abre === '{' ? '}' : ']';
        $ini = strpos($texto, $abre);
        $fim = strrpos($texto, $fecha);
        if ($ini === false || $fim === false || $fim < $ini) {
            return [];
        }
        $dados = json_decode(substr($texto, $ini, $fim - $ini + 1), true);

        return is_array($dados) ? $dados : [];
    }
}
