<?php

namespace App\Licitacao\Regras;

/**
 * Indicadores gerenciais das contratações (art. 11, parágrafo único: governança e acompanhamento pela alta
 * administração): visão por secretaria, perfil dos fornecedores contratados e fomento ao mercado local e regional
 * (art. 40, § 2º, II, e art. 4º, que aplica os arts. 42 a 49 da Lei Complementar 123/2006 às ME e EPP).
 */
final class IndicadoresGestao
{
    public const PORTES = ['ME' => 'Microempresa', 'EPP' => 'Empresa de pequeno porte', 'DEMAIS' => 'Demais', '' => 'Não informado'];

    /**
     * @param  list<array{unidade: string, andamento: bool, valor: float, itens_pca?: int, itens_pca_usados?: int, contratos?: int, valor_contratos?: float}>  $linhas
     * @return list<array{unidade: string, processos: int, andamento: int, concluidos: int, valor: float, itens_pca: int, itens_pca_usados: int, execucao_pca: ?int, contratos: int, valor_contratos: float, participacao: ?float}>
     */
    public static function porUnidade(array $linhas): array
    {
        $u = [];
        foreach ($linhas as $l) {
            $k = $l['unidade'] ?: 'Sem unidade';
            $u[$k] ??= ['unidade' => $k, 'processos' => 0, 'andamento' => 0, 'concluidos' => 0, 'valor' => 0.0, 'itens_pca' => 0, 'itens_pca_usados' => 0, 'contratos' => 0, 'valor_contratos' => 0.0];
            if (isset($l['andamento'])) {
                $u[$k]['processos']++;
                $u[$k][$l['andamento'] ? 'andamento' : 'concluidos']++;
                $u[$k]['valor'] += (float) $l['valor'];
            }
            $u[$k]['itens_pca'] += (int) ($l['itens_pca'] ?? 0);
            $u[$k]['itens_pca_usados'] += (int) ($l['itens_pca_usados'] ?? 0);
            $u[$k]['contratos'] += (int) ($l['contratos'] ?? 0);
            $u[$k]['valor_contratos'] += (float) ($l['valor_contratos'] ?? 0);
        }
        $total = array_sum(array_column($u, 'valor'));
        foreach ($u as &$x) {
            $x['valor'] = round($x['valor'], 2);
            $x['valor_contratos'] = round($x['valor_contratos'], 2);
            $x['execucao_pca'] = $x['itens_pca'] ? (int) round($x['itens_pca_usados'] / $x['itens_pca'] * 100) : null;
            $x['participacao'] = $total > 0 ? round($x['valor'] / $total * 100, 1) : null;
        }
        unset($x);
        $u = array_values($u);
        usort($u, fn ($a, $b) => [$b['valor'], $a['unidade']] <=> [$a['valor'], $b['unidade']]);

        return $u;
    }

    /**
     * Perfil dos fornecedores contratados: porte, UF e município, por quantidade e valor.
     *
     * @param  list<array{fornecedor: int, porte: ?string, uf: ?string, municipio: ?string, valor: float}>  $contratos
     * @return array{fornecedores: int, valor: float, porte: list<array{chave: string, rotulo: string, fornecedores: int, valor: float, pct_valor: ?float}>, uf: list<array{chave: string, rotulo: string, fornecedores: int, valor: float, pct_valor: ?float}>, municipio: list<array{chave: string, rotulo: string, fornecedores: int, valor: float, pct_valor: ?float}>, pct_me_epp: ?float}
     */
    public static function perfilFornecedores(array $contratos): array
    {
        $total = round(array_sum(array_column($contratos, 'valor')), 2);
        $dimensao = function (callable $chave, callable $rotulo) use ($contratos, $total): array {
            $g = [];
            foreach ($contratos as $c) {
                $k = $chave($c);
                $g[$k] ??= ['chave' => $k, 'rotulo' => $rotulo($k), 'fornecedores' => [], 'valor' => 0.0];
                $g[$k]['fornecedores'][$c['fornecedor']] = true;
                $g[$k]['valor'] += (float) $c['valor'];
            }
            $r = array_map(fn ($x) => ['chave' => $x['chave'], 'rotulo' => $x['rotulo'], 'fornecedores' => count($x['fornecedores']), 'valor' => round($x['valor'], 2), 'pct_valor' => $total > 0 ? round($x['valor'] / $total * 100, 1) : null], array_values($g));
            usort($r, fn ($a, $b) => [$b['valor'], $a['rotulo']] <=> [$a['valor'], $b['rotulo']]);

            return $r;
        };
        $porte = $dimensao(fn ($c) => array_key_exists((string) $c['porte'], self::PORTES) ? (string) $c['porte'] : '', fn ($k) => self::PORTES[$k]);
        $meEpp = array_sum(array_map(fn ($p) => in_array($p['chave'], ['ME', 'EPP'], true) ? $p['valor'] : 0, $porte));

        return [
            'fornecedores' => count(array_unique(array_column($contratos, 'fornecedor'))), 'valor' => $total, 'porte' => $porte,
            'uf' => $dimensao(fn ($c) => strtoupper((string) $c['uf']) ?: '—', fn ($k) => $k === '—' ? 'Não informada' : $k),
            'municipio' => array_slice($dimensao(fn ($c) => trim((string) $c['municipio']) !== '' ? mb_strtoupper(trim($c['municipio'])).'/'.strtoupper((string) $c['uf']) : '—', fn ($k) => $k === '—' ? 'Não informado' : $k), 0, 10),
            'pct_me_epp' => $total > 0 ? round($meEpp / $total * 100, 1) : null,
        ];
    }

    /**
     * Quanto do valor contratado ficou no município do órgão, no restante da UF e fora dela.
     *
     * @param  list<array{fornecedor: int, porte: ?string, uf: ?string, municipio: ?string, valor: float}>  $contratos
     * @return array{local: array{valor: float, pct: ?float, fornecedores: int}, regional: array{valor: float, pct: ?float, fornecedores: int}, fora: array{valor: float, pct: ?float, fornecedores: int}, sem_endereco: array{valor: float, pct: ?float, fornecedores: int}, me_epp_local: float}
     */
    public static function fomentoLocal(array $contratos, ?string $municipio, ?string $uf): array
    {
        $municipio = mb_strtoupper(trim((string) $municipio));
        $uf = strtoupper(trim((string) $uf));
        $faixas = ['local' => [], 'regional' => [], 'fora' => [], 'sem_endereco' => []];
        $meEppLocal = 0.0;
        foreach ($contratos as $c) {
            $cm = mb_strtoupper(trim((string) $c['municipio']));
            $cu = strtoupper(trim((string) $c['uf']));
            $faixa = match (true) {
                $cu === '' => 'sem_endereco',
                $cu === $uf && $cm !== '' && $cm === $municipio => 'local',
                $cu === $uf => 'regional',
                default => 'fora',
            };
            $faixas[$faixa][] = $c;
            if ($faixa === 'local' && in_array($c['porte'], ['ME', 'EPP'], true)) {
                $meEppLocal += (float) $c['valor'];
            }
        }
        $total = array_sum(array_column($contratos, 'valor'));

        return array_map(fn ($l) => ['valor' => round(array_sum(array_column($l, 'valor')), 2), 'pct' => $total > 0 ? round(array_sum(array_column($l, 'valor')) / $total * 100, 1) : null,
            'fornecedores' => count(array_unique(array_column($l, 'fornecedor')))], $faixas) + ['me_epp_local' => round($meEppLocal, 2)];
    }
}
