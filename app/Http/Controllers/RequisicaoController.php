<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\GestaoOrdens;
use App\Licitacao\RegraViolada;
use App\Models\AtaItem;
use App\Models\Requisicao;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Requisições de fornecimento sobre o saldo das atas (art. 82 e seguintes). */
class RequisicaoController extends Controller
{
    public function __construct(private GestaoOrdens $servico) {}

    public function index(Request $request): View
    {
        $u = $request->user();
        $gerencia = $u->can('contratos.gerenciar');

        $requisicoes = Requisicao::with('ataItem.ata.fornecedor', 'ataItem.item', 'requisitante', 'unidade')
            ->when(! $gerencia, fn ($q) => $q->where('requisitante_id', $u->id))
            ->latest('id')->limit(100)->get();

        $itens = $u->can('requisicoes.criar')
            ? AtaItem::with('ata.fornecedor', 'item')->whereHas('ata', fn ($q) => $q->where('situacao', 'vigente')->whereDate('vigencia_fim', '>=', today()))->get()->filter(fn ($ai) => $ai->saldo() > 0)
            : collect();

        return view('requisicoes.index', ['requisicoes' => $requisicoes, 'itens' => $itens, 'unidades' => Unidade::orderBy('sigla')->get(['id', 'sigla']), 'gerencia' => $gerencia]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);
        $d = $request->validate(['ata_item_id' => ['required', 'integer'], 'quantidade' => ['required', 'numeric', 'gt:0'], 'unidade_id' => ['nullable', 'integer'], 'justificativa' => ['required', 'string', 'max:2000']]);
        $ai = AtaItem::findOrFail($d['ata_item_id']);

        try {
            $this->servico->requisitar($ai, (float) $d['quantidade'], $d['unidade_id'] ?? $request->user()->unidade_id ?? null, $d['justificativa'], $request->user());
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível registrar a requisição.')->with('motivos', $e->motivos);
        }

        return redirect()->route('requisicoes.index')->with('sucesso', 'Requisição registrada. Ela aguarda a aprovação do gestor.');
    }

    public function decidir(Request $request, Requisicao $requisicao): RedirectResponse
    {
        $d = $request->validate(['acao' => ['required', 'in:aprovar,recusar'], 'motivo' => ['nullable', 'string', 'max:500']]);

        try {
            $this->servico->decidir($requisicao, $d['acao'] === 'aprovar', $d['motivo'] ?? null, $request->user());
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível decidir.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', $d['acao'] === 'aprovar' ? 'Requisição aprovada. Já pode virar ordem de fornecimento.' : 'Requisição recusada.');
    }
}
