<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Plano de Contratações Anual (art. 12, VII, da Lei 14.133). */
class PcaController extends Controller
{
    public function index(Request $request): View
    {
        $planos = PlanoContratacao::withCount('itens')->withSum('itens', 'valor_total_estimado')->orderByDesc('ano')->get();

        return view('pca.index', ['planos' => $planos, 'proximoAno' => (int) now()->addYear()->format('Y')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $this->orgaoDoUsuario($request);
        $dados = $request->validate([
            'ano' => ['required', 'integer', 'min:2023', 'max:2100', Rule::unique('planos_contratacao', 'ano')->where('orgao_id', $orgaoId)],
        ], ['ano.unique' => 'Já existe PCA para esse ano.']);

        $plano = PlanoContratacao::create(['orgao_id' => $orgaoId, 'ano' => $dados['ano'], 'situacao' => 'rascunho']);

        return redirect()->route('pca.show', $plano)->with('sucesso', "PCA {$plano->ano} criado. Inclua os itens.");
    }

    public function show(Request $request, PlanoContratacao $plano): View
    {
        $plano->load(['itens.unidade', 'aprovador']);
        $itens = $plano->itens;

        return view('pca.show', [
            'plano' => $plano,
            'itens' => $itens,
            'porCategoria' => $itens->groupBy('categoria')->map(fn ($g) => ['qtd' => $g->count(), 'valor' => (float) $g->sum('valor_total_estimado')])->sortByDesc('valor'),
            'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(),
            'editando' => $request->integer('item') ? $itens->firstWhere('id', $request->integer('item')) : null,
        ]);
    }

    public function salvarItem(Request $request, PlanoContratacao $plano, ?ItemPca $item = null): RedirectResponse
    {
        abort_if($item && $item->plano_contratacao_id !== $plano->id, 404);

        if (! $plano->editavel()) {
            return back()->with('erro', 'O PCA aprovado não pode ser alterado diretamente. Registre uma revisão do plano.');
        }

        $request->merge([
            'quantidade' => decimal_br($request->input('quantidade')),
            'valor_unitario_estimado' => decimal_br($request->input('valor_unitario_estimado')),
        ]);

        $dados = $request->validate([
            'material_servico' => ['required', Rule::in(['M', 'S'])],
            'categoria' => ['required', Rule::enum(CategoriaProcesso::class)],
            'codigo_catalogo' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'descricao' => ['required', 'string', 'max:2000'],
            'unidade_fornecimento' => ['required', 'string', 'max:30'],
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'valor_unitario_estimado' => ['required', 'numeric', 'gt:0'],
            'unidade_id' => ['nullable', 'integer', Rule::exists('unidades', 'id')->where('orgao_id', $plano->orgao_id)],
            'data_desejada' => ['nullable', 'date'],
            'prioridade' => ['required', Rule::in([1, 2, 3])],
            'natureza_despesa' => ['nullable', 'string', 'max:40'],
        ], ['codigo_catalogo.regex' => 'Use só os números do código CATMAT ou CATSER.']);

        if ($item) {
            $item->update($dados);
            $msg = "Item {$item->numero} atualizado.";
        } else {
            $item = $plano->itens()->create($dados + ['orgao_id' => $plano->orgao_id, 'numero' => ((int) $plano->itens()->max('numero')) + 1]);
            $msg = "Item {$item->numero} incluído no PCA.";
        }

        return redirect()->route('pca.show', $plano)->with('sucesso', $msg);
    }

    public function excluirItem(PlanoContratacao $plano, ItemPca $item): RedirectResponse
    {
        abort_if($item->plano_contratacao_id !== $plano->id, 404);

        if (! $plano->editavel()) {
            return back()->with('erro', 'O PCA aprovado não pode ser alterado diretamente.');
        }

        $item->delete();

        return back()->with('sucesso', "Item {$item->numero} excluído.");
    }

    public function enviarAprovacao(PlanoContratacao $plano): RedirectResponse
    {
        if ($plano->situacao !== 'rascunho' || $plano->itens()->doesntExist()) {
            return back()->with('erro', 'Só um PCA em elaboração e com itens pode ir para aprovação.');
        }

        $plano->update(['situacao' => 'em_aprovacao']);

        return back()->with('sucesso', "PCA {$plano->ano} enviado para aprovação da autoridade competente.");
    }

    public function aprovar(Request $request, PlanoContratacao $plano): RedirectResponse
    {
        if ($plano->situacao !== 'em_aprovacao') {
            return back()->with('erro', 'Este PCA não está aguardando aprovação.');
        }

        $plano->forceFill(['situacao' => 'aprovado', 'aprovado_por' => $request->user()->id, 'aprovado_em' => now()])->save();

        return back()->with('sucesso', "PCA {$plano->ano} aprovado. Ele deve ser divulgado no sítio oficial e no PNCP (art. 12, § 1º).");
    }

    private function orgaoDoUsuario(Request $request): int
    {
        $orgaoId = $request->user()->orgao_id ?? $request->integer('orgao_id');
        abort_unless($orgaoId, 422, 'Escolha o órgão.');

        return $orgaoId;
    }
}
