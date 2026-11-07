<?php

namespace App\Licitacao\Regras;

/**
 * Descrição padronizada de item (art. 40, § 1º, I: especificação do produto, preferencialmente conforme catálogo
 * eletrônico de padronização; art. 19, II: catálogo). Barra descrição vaga ("diversos", "em geral") e acha
 * itens parecidos para evitar cadastro duplicado.
 */
final class DescricaoCatalogo
{
    /** Termos que deixam o objeto indefinido: não permitem comparar preço nem julgar proposta. */
    public const TERMOS_VAGOS = [
        'diversos', 'diversas', 'variados', 'variadas', 'varios', 'varias', 'etc', 'outros', 'outras', 'em geral',
        'material de consumo', 'materiais de consumo', 'servicos gerais', 'conforme necessidade', 'a definir', 'qualquer', 'generico', 'generica', 'sortidos', 'sortidas',
    ];

    public const TAMANHO_MINIMO = 20;

    public const PALAVRAS_MINIMAS = 3;

    public const LIMIAR_SEMELHANCA = 0.75;

    /** Termos vagos encontrados na descrição (vazio = nenhum). */
    public static function termosVagos(string $descricao): array
    {
        $n = ' '.self::normalizar($descricao).' ';

        return array_values(array_filter(self::TERMOS_VAGOS, fn (string $t) => str_contains($n, ' '.$t.' ')));
    }

    /**
     * Motivos que impedem cadastrar o item no catálogo do órgão.
     *
     * @param  list<array{nome?: string, valor?: string}>  $atributos
     * @return list<string>
     */
    public static function motivos(string $tipo, string $descricao, array $atributos): array
    {
        $m = [];
        $descricao = trim($descricao);
        if (mb_strlen($descricao) < self::TAMANHO_MINIMO || count(self::palavras($descricao)) < self::PALAVRAS_MINIMAS) {
            $m[] = sprintf('Descreva o item com pelo menos %d caracteres e %d palavras: o que é, material ou característica principal e medida (art. 40, § 1º, I).', self::TAMANHO_MINIMO, self::PALAVRAS_MINIMAS);
        }
        if ($vagos = self::termosVagos($descricao)) {
            $m[] = 'A descrição tem termo vago ('.implode(', ', array_map(fn ($t) => '"'.$t.'"', $vagos)).'). Especifique o item: termo genérico impede comparar preços e julgar propostas (art. 40, § 1º, I).';
        }
        $preenchidos = array_filter($atributos, fn ($a) => trim(($a['nome'] ?? '').($a['valor'] ?? '')) !== '');
        $completos = array_filter($preenchidos, fn ($a) => trim($a['nome'] ?? '') !== '' && trim($a['valor'] ?? '') !== '');
        if (count($completos) !== count($preenchidos)) {
            $m[] = 'Cada atributo precisa de nome e valor (ex.: Gramatura · 75 g/m²).';
        }
        if ($tipo === 'M' && count($completos) < 1) {
            $m[] = 'Material precisa de ao menos um atributo de qualidade, rendimento, durabilidade ou segurança (art. 40, § 1º, I).';
        }
        $nomes = array_map(fn ($a) => self::normalizar((string) $a['nome']), $completos);
        if (count($nomes) !== count(array_unique($nomes))) {
            $m[] = 'Há atributo repetido.';
        }

        return $m;
    }

    /** Minúsculas, sem acento e sem pontuação, com espaços simples. */
    public static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Semelhança de 0 a 1 entre duas descrições (palavras em comum sobre o total de palavras distintas). */
    public static function semelhanca(string $a, string $b): float
    {
        $x = array_unique(self::palavras($a));
        $y = array_unique(self::palavras($b));
        if (! $x || ! $y) {
            return 0.0;
        }

        return count(array_intersect($x, $y)) / count(array_unique([...$x, ...$y]));
    }

    /**
     * Itens parecidos com a descrição, do mais para o menos parecido.
     *
     * @param  array<int, string>  $candidatos  id => descrição
     * @return array<int, float> id => semelhança
     */
    public static function parecidos(string $descricao, array $candidatos, float $limiar = self::LIMIAR_SEMELHANCA): array
    {
        $r = [];
        foreach ($candidatos as $id => $d) {
            $s = self::semelhanca($descricao, $d);
            if ($s >= $limiar) {
                $r[$id] = round($s, 2);
            }
        }
        arsort($r);

        return $r;
    }

    /** @return list<string> */
    private static function palavras(string $s): array
    {
        return array_values(array_filter(explode(' ', self::normalizar($s)), fn ($p) => mb_strlen($p) >= 2));
    }
}
