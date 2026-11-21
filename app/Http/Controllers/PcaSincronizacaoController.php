<?php

namespace App\Http\Controllers;

use App\Licitacao\Regras\ComparacaoPcaPncp;
use App\Models\ItemPca;
use App\Models\PcaUnidade;
use App\Models\PlanoContratacao;
use App\Pncp\NumeroControle;
use App\Pncp\PncpCliente;
use App\Support\Documento;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Confere o PCA publicado no PNCP com o plano do sistema, unidade por unidade (art. 12, § 1º, e art. 174).
 * A consulta usa o serviço de itens do plano de uma unidade e ano do manual do PNCP.
 */
class PcaSincronizacaoController extends Controller
{
    public function show(PlanoContratacao $plano): View
    {
        return view('pca.sincronizar', ['plano' => $plano, 'partes' => $plano->pcasUnidade()->with('unidade')->whereNotNull('numero_controle_pncp')->get()]);
    }

    public function sincronizar(Request $request, PlanoContratacao $plano, PncpCliente $cliente): RedirectResponse
    {
        if (! $cliente->credenciaisConfiguradas()) {
            return back()->with('erro', 'Configure as credenciais do PNCP para consultar o portal.');
        }
        $partes = $plano->pcasUnidade()->with('unidade')->whereNotNull('numero_controle_pncp')->get();
        if ($partes->isEmpty()) {
            return back()->with('erro', 'O PCA ainda não foi publicado no PNCP.');
        }

        $cnpj = Documento::digitos((string) $plano->orgao->cnpj);
        $ambiente = $plano->orgao->pncp_ambiente ?? config('nexplangov.pncp.ambiente');
        $falhas = [];

        foreach ($partes as $parte) {
            [$ano, $seq] = NumeroControle::anoSequencial($parte->numero_controle_pncp);
            try {
                $remotos = $this->itensRemotos($cliente, $ambiente, "/v1/orgaos/{$cnpj}/pca/{$ano}/{$seq}/itens");
            } catch (ConnectionException|Throwable $e) {
                $falhas[] = ($parte->unidade?->sigla ?? 'Unidade').': '.$e->getMessage();

                continue;
            }

            $comparacao = ComparacaoPcaPncp::comparar($this->itensLocais($parte), $remotos);
            $numeros = array_values(array_unique(array_map(fn ($r) => (int) ($r['numeroItem'] ?? 0), $remotos)));
            sort($numeros);
            $parte->update(['itens_publicados' => $numeros, 'sincronizacao' => $comparacao, 'sincronizado_em' => now()]);
        }

        return redirect()->route('pca.sincronizar', $plano)->with($falhas ? 'erro' : 'sucesso', $falhas
            ? 'Não foi possível consultar o PNCP para: '.implode(' ', $falhas)
            : 'PCA conferido com o PNCP.');
    }

    /** @return list<array<string, mixed>> todas as páginas de itens do plano da unidade */
    private function itensRemotos(PncpCliente $cliente, string $ambiente, string $rota): array
    {
        $itens = [];
        for ($pagina = 1; $pagina <= 20; $pagina++) {
            $resposta = $cliente->consultar($ambiente, $rota.'?pagina='.$pagina.'&tamanhoPagina=500');
            if ($resposta->status() === 204 || $resposta->status() === 404) {
                break;
            }
            if (! $resposta->successful()) {
                throw new \RuntimeException('PNCP respondeu HTTP '.$resposta->status().'.');
            }
            $json = $resposta->json();
            $lote = is_array($json) && array_is_list($json) ? $json : ($json['data'] ?? []);
            $itens = [...$itens, ...$lote];
            if (count($lote) < 500) {
                break;
            }
        }

        return $itens;
    }

    /** @return list<array{numero: int, descricao: string, quantidade: float, valor_unitario: float, data: ?string, codigo: ?string}> */
    private function itensLocais(PcaUnidade $parte): array
    {
        return $parte->itens()->map(fn (ItemPca $i) => ['numero' => (int) $i->numero, 'descricao' => mb_substr($i->descricao, 0, 2048), 'quantidade' => (float) $i->quantidade,
            'valor_unitario' => (float) $i->valor_unitario_estimado, 'data' => $i->data_desejada?->format('Y-m-d'), 'codigo' => $i->codigo_catalogo ?: null])->all();
    }
}
