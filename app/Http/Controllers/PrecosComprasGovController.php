<?php

namespace App\Http\Controllers;

use App\Integracoes\ComprasGov\PesquisaPrecosComprasGov;
use App\Integracoes\IntegracaoIndisponivel;
use App\Licitacao\Enums\FontePreco;
use App\Models\ItemProcesso;
use App\Models\Processo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Importa preços de compras públicas do Compras.gov.br para a pesquisa de preços do item (art. 23, § 1º, II). */
class PrecosComprasGovController extends Controller
{
    public function __construct(private PesquisaPrecosComprasGov $api) {}

    public function mostrar(Processo $processo, ItemProcesso $item): View|RedirectResponse
    {
        $this->exigir($processo, $item);

        if (! $item->codigo_catalogo) {
            return back()->with('erro', 'Informe o código CATMAT/CATSER do item para buscar preços no Compras.gov.br.');
        }

        try {
            $precos = $this->api->buscar($item->material_servico, $item->codigo_catalogo);
            $falha = null;
        } catch (IntegracaoIndisponivel $e) {
            $precos = [];
            $falha = $e->getMessage();
        }

        $importados = $item->cotacoes()->where('referencia', 'like', 'Compras.gov.br%')->pluck('referencia')->all();

        return view('processos.precos-comprasgov', compact('processo', 'item', 'precos', 'falha', 'importados'));
    }

    public function importar(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $this->exigir($processo, $item);
        $d = $request->validate(['selecionados' => ['required', 'array', 'min:1', 'max:30'], 'selecionados.*' => ['string', 'max:120']]);

        try {
            $precos = collect($this->api->buscar($item->material_servico, (string) $item->codigo_catalogo))->keyBy(fn ($p) => $p['id'].'|'.$p['data'].'|'.$p['valor']);
        } catch (IntegracaoIndisponivel $e) {
            return back()->with('erro', $e->getMessage());
        }

        $n = 0;
        foreach ($d['selecionados'] as $chave) {
            // Só entra o que a API devolveu agora: o navegador não escolhe valor nem data.
            if (! $p = $precos->get($chave)) {
                continue;
            }
            $ref = mb_substr("Compras.gov.br · {$p['orgao']}".($p['uasg'] ? " (UASG {$p['uasg']})" : '')." · compra {$p['id']}", 0, 255);
            if ($item->cotacoes()->where('referencia', $ref)->where('valor_unitario', $p['valor'])->exists()) {
                continue;
            }
            $item->cotacoes()->create(['orgao_id' => $processo->orgao_id, 'fonte' => FontePreco::ContratacoesSimilares->value, 'referencia' => $ref, 'documento_fonte' => $p['cnpj'], 'valor_unitario' => $p['valor'], 'data_cotacao' => $p['data']]);
            $n++;
        }

        app(ItemProcessoController::class)->recalcular($item);

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])->with('sucesso', "{$n} preço(s) importado(s) do Compras.gov.br como contratações similares (art. 23, § 1º, II).");
    }

    private function exigir(Processo $processo, ItemProcesso $item): void
    {
        abort_unless($item->processo_id === $processo->id, 404);
        abort_unless($processo->editavel(), 422, 'Itens e preços só mudam na fase preparatória.');
    }
}
