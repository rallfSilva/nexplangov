<?php

namespace App\Http\Controllers;

use App\Models\Demanda;
use App\Models\ItemPca;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Documento de formalização da demanda (DFD), feito pela área requisitante. */
class DemandaController extends Controller
{
    public function index(Request $request): View
    {
        $demandas = Demanda::with(['unidade', 'solicitante', 'itemPca'])
            ->when($request->filled('situacao'), fn ($q) => $q->where('situacao', $request->string('situacao')))
            ->when(! $request->user()->can('pca.gerenciar'), fn ($q) => $q->where('solicitante_id', $request->user()->id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('demandas.index', ['demandas' => $demandas]);
    }

    public function create(): View
    {
        $ano = (int) now()->format('Y');

        return view('demandas.form', [
            'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(),
            'itensPca' => ItemPca::whereHas('plano', fn ($q) => $q->whereIn('ano', [$ano, $ano + 1]))->orderBy('numero')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422, 'Demanda é registrada por usuário de um órgão.');

        $request->merge(['quantidade' => decimal_br($request->input('quantidade')), 'valor_estimado' => decimal_br($request->input('valor_estimado'))]);

        $dados = $request->validate([
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('orgao_id', $orgaoId)],
            'item_pca_id' => ['nullable', 'integer', Rule::exists('itens_pca', 'id')->where('orgao_id', $orgaoId)],
            'objeto' => ['required', 'string', 'max:1000'],
            'justificativa' => ['required', 'string', 'min:30', 'max:10000'],
            'quantidade' => ['nullable', 'numeric', 'gt:0'],
            'valor_estimado' => ['nullable', 'numeric', 'gt:0'],
            'data_pretendida' => ['nullable', 'date', 'after_or_equal:today'],
            'prioridade' => ['required', Rule::in([1, 2, 3])],
        ], ['justificativa.min' => 'Explique a necessidade com pelo menos 30 caracteres: ela fundamenta o processo.']);

        $ano = (int) now()->format('Y');
        $seq = Demanda::where('ano', $ano)->count() + 1;

        $demanda = Demanda::create($dados + [
            'orgao_id' => $orgaoId,
            'solicitante_id' => $request->user()->id,
            'ano' => $ano,
            'numero' => str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'situacao' => 'enviada',
        ]);

        return redirect()->route('demandas.index')->with('sucesso', "Demanda {$demanda->numero}/{$demanda->ano} enviada ao planejamento.");
    }

    public function analisar(Request $request, Demanda $demanda): RedirectResponse
    {
        $dados = $request->validate([
            'decisao' => ['required', Rule::in(['aceita', 'recusada'])],
            'motivo_recusa' => ['required_if:decisao,recusada', 'nullable', 'string', 'max:2000'],
        ], ['motivo_recusa.required_if' => 'Explique por que a demanda foi devolvida.']);

        $demanda->update(['situacao' => $dados['decisao'], 'motivo_recusa' => $dados['motivo_recusa'] ?? null]);

        return back()->with('sucesso', $dados['decisao'] === 'aceita' ? 'Demanda aceita. Você já pode abrir o processo a partir dela.' : 'Demanda devolvida ao requisitante.');
    }
}
