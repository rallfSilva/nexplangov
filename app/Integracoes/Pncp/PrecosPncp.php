<?php

namespace App\Integracoes\Pncp;

use App\Integracoes\IntegracaoIndisponivel;
use App\Licitacao\Regras\CotacaoAutomatica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Preços homologados publicados no PNCP por órgãos de todas as esferas (art. 174 e art. 23, § 1º, II, da Lei 14.133/2021).
 *
 * O PNCP não busca item por descrição: procura as contratações pelas palavras do item, lê os itens de cada uma,
 * fica com os de descrição parecida e consulta o resultado (valor homologado). Só leitura, sem credencial.
 * Os nomes dos campos seguem o manual de consultas do PNCP; confirme-os na homologação (ver docs/integracoes.md).
 */
final class PrecosPncp
{
    /**
     * @return list<array{id: string, valor: float, data: string, orgao: string, uasg: ?string, fornecedor: ?string, cnpj: ?string, unidade: ?string, quantidade: ?float, descricao: ?string, url: ?string, origem: string}>
     */
    public function buscar(string $termos, string $descricaoItem): array
    {
        $termos = trim($termos);
        if (mb_strlen($termos) < 3) {
            return [];
        }

        return Cache::remember('pncp:precos:'.md5(mb_strtolower($termos.'|'.$descricaoItem)), now()->addHours(6), fn () => $this->consultar($termos, $descricaoItem));
    }

    private function consultar(string $termos, string $descricaoItem): array
    {
        $cfg = config('nexplangov.pncp_consulta');
        $limite = today()->subYear();
        $palavras = CotacaoAutomatica::palavrasChave($descricaoItem);
        $saida = [];

        try {
            $r = Http::timeout($cfg['timeout'])->acceptJson()->retry(2, 500, throw: false)->get($cfg['url_busca'], [
                'q' => $termos, 'tipos_documento' => 'edital', 'ordenacao' => '-data', 'status' => 'encerradas', 'pagina' => 1, 'tam_pagina' => $cfg['max_compras'],
            ]);
            if (! $r->successful()) {
                throw new IntegracaoIndisponivel("O PNCP respondeu com erro {$r->status()} na busca.");
            }

            foreach (array_slice($r->json('items') ?? [], 0, $cfg['max_compras']) as $compra) {
                $cnpj = preg_replace('/\D/', '', (string) ($compra['orgao_cnpj'] ?? ''));
                $ano = (int) ($compra['ano'] ?? 0);
                $seq = (int) ($compra['numero_sequencial'] ?? 0);
                if (strlen($cnpj) !== 14 || ! $ano || ! $seq) {
                    continue;
                }
                $base = "/v1/orgaos/{$cnpj}/compras/{$ano}/{$seq}";

                $itens = Http::baseUrl($cfg['url'])->timeout($cfg['timeout'])->acceptJson()->get("{$base}/itens", ['pagina' => 1, 'tamanhoPagina' => 100]);
                if (! $itens->successful()) {
                    continue;
                }

                $parecidos = collect($itens->json() ?? [])
                    ->filter(fn ($i) => is_array($i) && ($i['temResultado'] ?? true) && isset($i['numeroItem']))
                    ->map(fn ($i) => $i + ['_semelhanca' => CotacaoAutomatica::semelhanca($palavras, (string) ($i['descricao'] ?? ''))])
                    ->filter(fn ($i) => $i['_semelhanca'] >= 0.5)
                    ->sortByDesc('_semelhanca')
                    ->take($cfg['itens_por_compra']);

                foreach ($parecidos as $item) {
                    $res = Http::baseUrl($cfg['url'])->timeout($cfg['timeout'])->acceptJson()->get("{$base}/itens/{$item['numeroItem']}/resultados");
                    if (! $res->successful()) {
                        continue;
                    }
                    foreach ($res->json() ?? [] as $resultado) {
                        if (is_array($resultado) && ($n = $this->normalizar($resultado, $item, $compra, $cnpj, $ano, $seq)) && Carbon::parse($n['data'])->gte($limite)) {
                            $saida[] = $n;
                        }
                    }
                }
            }
        } catch (IntegracaoIndisponivel $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new IntegracaoIndisponivel('Não foi possível consultar o PNCP agora.');
        }

        usort($saida, fn ($a, $b) => strcmp($b['data'], $a['data']));

        return array_slice($saida, 0, 40);
    }

    private function normalizar(array $r, array $item, array $compra, string $cnpjOrgao, int $ano, int $seq): ?array
    {
        if (($r['situacaoCompraItemResultadoId'] ?? 1) == 2) { // resultado cancelado
            return null;
        }
        $valor = $r['valorUnitarioHomologado'] ?? null;
        $data = $r['dataResultado'] ?? $r['dataInclusao'] ?? $compra['data_publicacao_pncp'] ?? null;
        if (! is_numeric($valor) || (float) $valor <= 0 || ! $data) {
            return null;
        }
        try {
            $data = Carbon::parse($data)->toDateString();
        } catch (Throwable) {
            return null;
        }
        $fornecedor = preg_replace('/\D/', '', (string) ($r['niFornecedor'] ?? ''));

        return [
            'id' => "pncp:{$cnpjOrgao}-{$ano}-{$seq}-{$item['numeroItem']}",
            'valor' => round((float) $valor, 4),
            'data' => $data,
            'orgao' => (string) ($compra['orgao_nome'] ?? 'Órgão não informado').(isset($compra['municipio_nome']) ? " ({$compra['municipio_nome']}/".($compra['uf'] ?? '').')' : ''),
            'uasg' => null,
            'fornecedor' => $r['nomeRazaoSocialFornecedor'] ?? null,
            'cnpj' => strlen($fornecedor) === 14 ? vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($fornecedor)) : null,
            'unidade' => $item['unidadeMedida'] ?? null,
            'quantidade' => is_numeric($r['quantidadeHomologada'] ?? null) ? (float) $r['quantidadeHomologada'] : (is_numeric($item['quantidade'] ?? null) ? (float) $item['quantidade'] : null),
            'descricao' => isset($item['descricao']) ? mb_substr((string) $item['descricao'], 0, 1000) : null,
            'url' => "https://pncp.gov.br/app/editais/{$cnpjOrgao}/{$ano}/{$seq}",
            'origem' => 'PNCP',
        ];
    }
}
