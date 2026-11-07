<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\FontePreco;
use App\Licitacao\Regras\PesquisaPrecos;
use App\Models\Cotacao;
use App\Models\Fornecedor;
use App\Models\ItemPca;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Rules\Cnpj;
use App\Rules\CodigoDeCatalogo;
use App\Rules\DescricaoNaoVaga;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Itens do processo e pesquisa de preços (art. 23). */
class ItemProcessoController extends Controller
{
    public function store(Request $request, Processo $processo): RedirectResponse
    {
        $this->exigirFasePreparatoria($processo);
        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);

        $dados = $request->validate([
            'item_pca_id' => ['nullable', 'integer', Rule::exists('itens_pca', 'id')->where('orgao_id', $processo->orgao_id)],
            'material_servico' => ['required_without:item_pca_id', 'nullable', Rule::in(['M', 'S'])],
            'codigo_catalogo' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/', new CodigoDeCatalogo($request->input('material_servico'))],
            'descricao' => ['required_without:item_pca_id', 'nullable', 'string', 'max:2000', new DescricaoNaoVaga],
            'unidade_medida' => ['required_without:item_pca_id', 'nullable', 'string', 'max:30'],
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'tipo_beneficio' => ['required', Rule::in(array_keys(ItemProcesso::BENEFICIOS))],
        ]);

        if ($dados['item_pca_id'] ?? null) {
            $pca = ItemPca::findOrFail($dados['item_pca_id']);
            $dados['material_servico'] ??= $pca->material_servico;
            $dados['descricao'] ??= $pca->descricao;
            $dados['unidade_medida'] ??= $pca->unidade_fornecimento;
            $dados['codigo_catalogo'] ??= $pca->codigo_catalogo;
        }

        $processo->itens()->create($dados + [
            'orgao_id' => $processo->orgao_id,
            'numero' => ((int) $processo->itens()->max('numero')) + 1,
        ]);

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])->with('sucesso', 'Item incluído. Agora registre as cotações da pesquisa de preços.');
    }

    /**
     * Corrige descrição, quantidade, unidade, catálogo ou benefício ME/EPP do item na fase preparatória.
     * As cotações ficam; a quantidade nova refaz o total do item e o valor estimado do processo (art. 23).
     */
    public function update(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $this->doProcesso($processo, $item);
        $this->exigirFasePreparatoria($processo);
        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);

        $item->update($request->validate([
            'material_servico' => ['required', Rule::in(['M', 'S'])],
            'codigo_catalogo' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/', new CodigoDeCatalogo($request->input('material_servico'))],
            'descricao' => ['required', 'string', 'max:2000', new DescricaoNaoVaga],
            'unidade_medida' => ['required', 'string', 'max:30'],
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'tipo_beneficio' => ['required', Rule::in(array_keys(ItemProcesso::BENEFICIOS))],
        ]));
        $this->recalcular($item);

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])->withFragment("item-{$item->id}")->with('sucesso', "Item {$item->numero} alterado e valor estimado recalculado.");
    }

    public function destroy(Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $this->doProcesso($processo, $item);
        $this->exigirFasePreparatoria($processo);
        $item->delete();
        $processo->recalcularValor();

        return back()->with('sucesso', "Item {$item->numero} excluído.");
    }

    public function metodo(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $this->doProcesso($processo, $item);
        $this->exigirFasePreparatoria($processo);
        $item->update($request->validate(['metodo_preco' => ['required', Rule::in(array_keys(PesquisaPrecos::METODOS))]]));
        $this->recalcular($item);

        return back()->with('sucesso', 'Método do preço de referência alterado.');
    }

    public function adicionarCotacao(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $this->doProcesso($processo, $item);
        $this->exigirFasePreparatoria($processo);
        $dados = $this->validarCotacao($request);

        $item->cotacoes()->create($dados + ['orgao_id' => $processo->orgao_id]);
        $this->recalcular($item);

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])->with('sucesso', 'Cotação registrada e preço de referência recalculado.');
    }

    /**
     * Corrige uma cotação digitada com erro sem precisar excluir e lançar de novo. Passa pelas mesmas
     * validações da inclusão e o preço de referência é recalculado (art. 23, § 1º). A alteração fica na auditoria.
     */
    public function editarCotacao(Request $request, Processo $processo, Cotacao $cotacao): RedirectResponse
    {
        $this->doProcesso($processo, $cotacao->item);
        $this->exigirFasePreparatoria($processo);

        $cotacao->update($this->validarCotacao($request));
        $this->recalcular($cotacao->item);

        return redirect()->route('processos.show', [$processo, 'aba' => 'itens'])->with('sucesso', 'Cotação alterada e preço de referência recalculado.');
    }

    public function desconsiderarCotacao(Request $request, Processo $processo, Cotacao $cotacao): RedirectResponse
    {
        $this->doProcesso($processo, $cotacao->item);
        $this->exigirFasePreparatoria($processo);
        $dados = $request->validate(['motivo_desconsideracao' => ['required', 'string', 'min:10', 'max:255']], ['motivo_desconsideracao.min' => 'Explique em pelo menos 10 caracteres por que a cotação foi desconsiderada.']);

        $cotacao->update(['desconsiderada' => true] + $dados);
        $this->recalcular($cotacao->item);

        return back()->with('sucesso', 'Cotação desconsiderada, com o motivo registrado.');
    }

    public function excluirCotacao(Processo $processo, Cotacao $cotacao): RedirectResponse
    {
        $this->doProcesso($processo, $cotacao->item);
        $this->exigirFasePreparatoria($processo);
        $item = $cotacao->item;
        $cotacao->delete();
        $this->recalcular($item);

        return back()->with('sucesso', 'Cotação excluída.');
    }

    /** Resultado do item na seleção do fornecedor. */
    public function resultado(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $this->doProcesso($processo, $item);
        abort_unless(in_array($processo->situacao, ['em_julgamento', 'publicado'], true), 422, 'O resultado é registrado durante o julgamento.');
        $request->merge(['valor_unitario_homologado' => decimal_br($request->input('valor_unitario_homologado'))]);

        $dados = $request->validate([
            'situacao' => ['required', Rule::in(['pendente', 'cancelado', 'deserto'])],
            'fornecedor_vencedor_id' => ['nullable', 'required_if:situacao,pendente', 'integer', Rule::exists('fornecedores', 'id')->where('orgao_id', $processo->orgao_id)],
            'valor_unitario_homologado' => ['nullable', 'required_if:situacao,pendente', 'numeric', 'gt:0'],
        ]);

        if ($dados['fornecedor_vencedor_id'] ?? null) {
            $fornecedor = Fornecedor::findOrFail($dados['fornecedor_vencedor_id']);

            if ($sancao = $fornecedor->impedidoEm()) {
                return back()->with('erro', "{$fornecedor->razao_social} está impedido de contratar até ".($sancao->fim?->format('d/m/Y') ?? 'decisão em contrário').' (art. 156).');
            }
        }

        $item->update($dados);

        return back()->with('sucesso', "Resultado do item {$item->numero} registrado.");
    }

    public function recalcular(ItemProcesso $item): void
    {
        $item->load('cotacoes');
        $r = (new PesquisaPrecos)->calcular(
            $item->cotacoes->map(fn ($c) => ['valor' => $c->valor_unitario, 'fonte' => $c->fonte, 'data' => $c->data_cotacao, 'desconsiderada' => $c->desconsiderada, 'motivo' => $c->motivo_desconsideracao])->values()->all(),
            $item->metodo_preco,
        );

        $item->forceFill([
            'valor_unitario_estimado' => $r['valor_unitario'],
            'valor_total_estimado' => round($r['valor_unitario'] * (float) $item->quantidade, 2),
        ])->save();

        $item->processo->recalcularValor();
    }

    /**
     * Fontes do art. 23, § 1º; a cotação direta com fornecedor (inciso IV) exige o CNPJ.
     *
     * @return array<string, mixed>
     */
    private function validarCotacao(Request $request): array
    {
        $request->merge(['valor_unitario' => decimal_br($request->input('valor_unitario'))]);

        return $request->validate([
            'fonte' => ['required', Rule::enum(FontePreco::class)],
            'referencia' => ['required', 'string', 'max:255'],
            'documento_fonte' => ['nullable', 'required_if:fonte,fornecedor', new Cnpj],
            'valor_unitario' => ['required', 'numeric', 'gt:0'],
            'data_cotacao' => ['required', 'date', 'before_or_equal:today'],
        ], ['documento_fonte.required_if' => 'Na cotação direta, informe o CNPJ do fornecedor.']);
    }

    private function doProcesso(Processo $processo, ?ItemProcesso $item): void
    {
        abort_unless($item && $item->processo_id === $processo->id, 404);
    }

    private function exigirFasePreparatoria(Processo $processo): void
    {
        abort_unless($processo->editavel(), 422, 'Itens e preços só mudam na fase preparatória.');
    }
}
