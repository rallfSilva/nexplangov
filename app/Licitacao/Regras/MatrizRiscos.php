<?php

namespace App\Licitacao\Regras;

/**
 * Matriz de riscos estruturada (art. 6º, XXVII, art. 18, X, e art. 22): cada risco com probabilidade, impacto,
 * nível, a quem cabe (alocação, art. 103) e o tratamento. A matriz é obrigatória em obras e serviços de grande
 * vulto e na contratação integrada ou semi-integrada (art. 22, § 3º). A sugestão da IA passa pelas mesmas regras
 * e só entra se o servidor aceitar.
 */
final class MatrizRiscos
{
    public const ALOCACOES = ['contratante' => 'Contratante', 'contratado' => 'Contratado', 'compartilhado' => 'Compartilhado'];

    public const ESCALA = [1 => 'Muito baixa', 2 => 'Baixa', 3 => 'Média', 4 => 'Alta', 5 => 'Muito alta'];

    /** Nível a partir do qual o risco precisa de tratamento descrito. */
    public const NIVEL_TRATAMENTO = 10;

    public static function nivel(int $probabilidade, int $impacto): int
    {
        return $probabilidade * $impacto;
    }

    /** @return array{0: string, 1: string} rótulo e classe visual */
    public static function classe(int $nivel): array
    {
        return match (true) {
            $nivel >= 20 => ['Extremo', 'b-err'],
            $nivel >= self::NIVEL_TRATAMENTO => ['Alto', 'b-warn'],
            $nivel >= 5 => ['Médio', 'b-info'],
            default => ['Baixo', 'b-ok'],
        };
    }

    /**
     * @param  array{risco?: string, probabilidade?: mixed, impacto?: mixed, alocacao?: ?string, tratamento?: ?string}  $r
     * @return list<string>
     */
    public static function motivos(array $r): array
    {
        $m = [];
        if (mb_strlen(trim((string) ($r['risco'] ?? ''))) < 10) {
            $m[] = 'Descreva o risco (mínimo 10 caracteres).';
        }
        $p = filter_var($r['probabilidade'] ?? null, FILTER_VALIDATE_INT);
        $i = filter_var($r['impacto'] ?? null, FILTER_VALIDATE_INT);
        if (! isset(self::ESCALA[$p]) || ! isset(self::ESCALA[$i])) {
            $m[] = 'Probabilidade e impacto vão de 1 (muito baixo) a 5 (muito alto).';
        }
        if (! array_key_exists((string) ($r['alocacao'] ?? ''), self::ALOCACOES)) {
            $m[] = 'Diga a quem cabe o risco: contratante, contratado ou compartilhado (art. 103).';
        }
        if (! $m && self::nivel($p, $i) >= self::NIVEL_TRATAMENTO && mb_strlen(trim((string) ($r['tratamento'] ?? ''))) < 15) {
            $m[] = 'Risco alto ou extremo precisa de ação de prevenção ou contingência (art. 18, X).';
        }

        return $m;
    }

    /** @return list<string> */
    public static function motivosMatriz(array $riscos, bool $obrigatoria): array
    {
        if ($obrigatoria && $riscos === []) {
            return ['A matriz de alocação de riscos é obrigatória nesta contratação (art. 22, § 3º).'];
        }

        return [];
    }

    /**
     * Texto dos campos da peça "Mapa de riscos" a partir da matriz.
     *
     * @param  list<array{risco: string, causa?: ?string, consequencia?: ?string, probabilidade: int, impacto: int, alocacao: string, tratamento?: ?string, responsavel?: ?string}>  $riscos
     * @return array{riscos: string, tratamento: string, matriz_alocacao: string}
     */
    public static function textoDaPeca(array $riscos): array
    {
        $r = $t = $a = [];
        foreach (array_values($riscos) as $k => $x) {
            $n = $k + 1;
            $nivel = self::nivel($x['probabilidade'], $x['impacto']);
            $r[] = sprintf('%d. %s%s%s Probabilidade %s, impacto %s, nível %d (%s).', $n, trim($x['risco']), ! empty($x['causa']) ? ' Causa: '.trim($x['causa']).'.' : '',
                ! empty($x['consequencia']) ? ' Consequência: '.trim($x['consequencia']).'.' : '', mb_strtolower(self::ESCALA[$x['probabilidade']]), mb_strtolower(self::ESCALA[$x['impacto']]), $nivel, mb_strtolower(self::classe($nivel)[0]));
            $t[] = sprintf('%d. %s%s', $n, trim((string) ($x['tratamento'] ?? '')) ?: 'Aceitar o risco e monitorar.', ! empty($x['responsavel']) ? ' Responsável: '.trim($x['responsavel']).'.' : '');
            $a[] = sprintf('%d. %s: %s.', $n, trim($x['risco']), mb_strtolower(self::ALOCACOES[$x['alocacao']]));
        }

        return ['riscos' => implode("\n", $r), 'tratamento' => implode("\n", $t), 'matriz_alocacao' => implode("\n", $a)];
    }

    /**
     * Lê a proposta da IA (JSON) e fica só com as linhas válidas. A IA nunca grava direto.
     *
     * @return list<array{risco: string, causa: ?string, consequencia: ?string, probabilidade: int, impacto: int, alocacao: string, tratamento: ?string, responsavel: ?string}>
     */
    public static function lerSugestao(string $texto): array
    {
        if (! preg_match('/\[.*\]/s', $texto, $m)) {
            return [];
        }
        $dados = json_decode($m[0], true);
        if (! is_array($dados)) {
            return [];
        }
        $r = [];
        foreach (array_slice($dados, 0, 15) as $x) {
            if (! is_array($x)) {
                continue;
            }
            $linha = [
                'risco' => mb_substr(trim((string) ($x['risco'] ?? '')), 0, 500), 'causa' => self::opcional($x['causa'] ?? null), 'consequencia' => self::opcional($x['consequencia'] ?? null),
                'probabilidade' => (int) ($x['probabilidade'] ?? 0), 'impacto' => (int) ($x['impacto'] ?? 0), 'alocacao' => mb_strtolower(trim((string) ($x['alocacao'] ?? ''))),
                'tratamento' => self::opcional($x['tratamento'] ?? null), 'responsavel' => self::opcional($x['responsavel'] ?? null),
            ];
            if (self::motivos($linha) === []) {
                $r[] = $linha;
            }
        }

        return $r;
    }

    private static function opcional(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : mb_substr($v, 0, 1000);
    }
}
