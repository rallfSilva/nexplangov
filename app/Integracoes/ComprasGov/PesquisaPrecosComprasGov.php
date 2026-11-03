<?php

namespace App\Integracoes\ComprasGov;

use App\Integracoes\IntegracaoIndisponivel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Preços praticados em compras públicas, pela API de dados abertos do Compras.gov.br (módulo de pesquisa de preço).
 * Servem como contratações similares (art. 23, § 1º, II): só entram compras do último ano.
 *
 * Os nomes dos campos da resposta seguem a documentação pública da API; confirme-os na homologação
 * (ver docs/integracoes.md). O mapeamento aceita as variações conhecidas.
 */
final class PesquisaPrecosComprasGov
{
    /** @return list<array{id: string, valor: float, data: string, orgao: string, uasg: ?string, fornecedor: ?string, cnpj: ?string, unidade: ?string, quantidade: ?float}> */
    public function buscar(string $tipo, string $codigo): array
    {
        if (! in_array($tipo, ['M', 'S'], true) || ! ctype_digit($codigo)) {
            return [];
        }

        return Cache::remember("comprasgov:precos:{$tipo}:{$codigo}", now()->addHours(6), fn () => $this->consultar($tipo, $codigo));
    }

    private function consultar(string $tipo, string $codigo): array
    {
        $cfg = config('nexplangov.compras_gov');
        $caminho = $tipo === 'M' ? $cfg['caminho_material'] : $cfg['caminho_servico'];
        $limite = today()->subYear();
        $itens = [];

        try {
            for ($pagina = 1; $pagina <= $cfg['max_paginas']; $pagina++) {
                $r = Http::baseUrl($cfg['url'])->timeout($cfg['timeout'])->acceptJson()->retry(2, 500, throw: false)
                    ->get($caminho, ['codigoItemCatalogo' => $codigo, 'pagina' => $pagina, 'tamanhoPagina' => 100]);

                if (! $r->successful()) {
                    throw new IntegracaoIndisponivel("O Compras.gov.br respondeu com erro {$r->status()}.");
                }

                $lista = $r->json('resultado') ?? $r->json('data') ?? [];
                foreach ($lista as $l) {
                    if ($n = $this->normalizar($l)) {
                        if (Carbon::parse($n['data'])->gte($limite)) {
                            $itens[] = $n;
                        }
                    }
                }

                $paginas = (int) ($r->json('totalPaginas') ?? 1);
                if ($pagina >= $paginas || ! $lista) {
                    break;
                }
            }
        } catch (IntegracaoIndisponivel $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new IntegracaoIndisponivel('Não foi possível consultar o Compras.gov.br agora.');
        }

        usort($itens, fn ($a, $b) => strcmp($b['data'], $a['data']));

        return array_slice($itens, 0, 60);
    }

    private function normalizar(array $l): ?array
    {
        $valor = $l['precoUnitario'] ?? $l['valorUnitario'] ?? $l['valorUnitarioHomologado'] ?? null;
        $data = $l['dataResultado'] ?? $l['dataCompra'] ?? $l['dataHomologacao'] ?? null;
        if (! is_numeric($valor) || (float) $valor <= 0 || ! $data) {
            return null;
        }

        try {
            $data = Carbon::parse($data)->toDateString();
        } catch (Throwable) {
            return null;
        }

        $cnpj = preg_replace('/\D/', '', (string) ($l['niFornecedor'] ?? $l['cnpjFornecedor'] ?? ''));

        return [
            'id' => (string) ($l['idCompra'] ?? $l['numeroCompra'] ?? md5(json_encode($l))),
            'valor' => round((float) $valor, 4),
            'data' => $data,
            'orgao' => (string) ($l['nomeOrgao'] ?? $l['nomeUasg'] ?? 'Órgão não informado'),
            'uasg' => isset($l['codigoUasg']) ? (string) $l['codigoUasg'] : null,
            'fornecedor' => $l['nomeFornecedor'] ?? null,
            'cnpj' => strlen($cnpj) === 14 ? vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj)) : null,
            'unidade' => $l['siglaUnidadeFornecimento'] ?? $l['nomeUnidadeFornecimento'] ?? $l['unidadeFornecimento'] ?? null,
            'quantidade' => is_numeric($l['quantidade'] ?? null) ? (float) $l['quantidade'] : null,
        ];
    }
}
