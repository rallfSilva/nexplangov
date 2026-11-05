<?php

namespace App\Http\Controllers;

use App\Licitacao\Regras\PrazoDemanda;
use App\Models\Demanda;
use App\Models\ItemPca;
use App\Models\PrazoDfd;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/** Documento de formalização da demanda (DFD), feito pela área requisitante. */
class DemandaController extends Controller
{
    public function index(Request $request): View
    {
        $demandas = Demanda::with(['unidade', 'solicitante', 'itemPca', 'vinculadas'])
            ->when($request->filled('situacao'), fn ($q) => $q->where('situacao', $request->string('situacao')))
            ->when(! $request->user()->can('pca.gerenciar'), fn ($q) => $q->where('solicitante_id', $request->user()->id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('demandas.index', ['demandas' => $demandas, 'prazos' => PrazoDfd::orderByDesc('exercicio')->limit(5)->get()]);
    }

    public function create(): View
    {
        $ano = (int) now()->format('Y');
        $prazos = PrazoDfd::whereIn('exercicio', [$ano, $ano + 1])->get()->keyBy('exercicio');
        // Padrão: o próximo exercício enquanto o prazo dele estiver aberto (as demandas do ano seguinte se planejam antes).
        $padrao = ($p = $prazos->get($ano + 1)) && ! PrazoDemanda::encerrado($p->data_limite, today()) ? $ano + 1 : $ano;

        return view('demandas.form', [
            'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(),
            'itensPca' => ItemPca::whereHas('plano', fn ($q) => $q->whereIn('ano', [$ano, $ano + 1]))->orderBy('numero')->get(),
            'exercicios' => [$ano, $ano + 1],
            'exercicioPadrao' => $padrao,
            'prazos' => $prazos,
            'outrasDemandas' => Demanda::where('situacao', '!=', 'recusada')->latest('id')->limit(200)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422, 'Demanda é registrada por usuário de um órgão.');

        $request->merge(['quantidade' => decimal_br($request->input('quantidade')), 'valor_estimado' => decimal_br($request->input('valor_estimado'))]);

        $ano = (int) now()->format('Y');
        $validador = validator($request->all(), [
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('orgao_id', $orgaoId)],
            'item_pca_id' => ['nullable', 'integer', Rule::exists('itens_pca', 'id')->where('orgao_id', $orgaoId)],
            'exercicio' => ['required', 'integer', Rule::in([$ano, $ano + 1])],
            'objeto' => ['required', 'string', 'max:1000'],
            'justificativa' => ['required', 'string', 'min:30', 'max:10000'],
            // Campos obrigatórios do DFD: quantidade, valor preliminar, data pretendida e prioridade (art. 12, VII).
            'quantidade' => ['required', 'numeric', 'gt:0'],
            'valor_estimado' => ['required', 'numeric', 'gt:0'],
            'data_pretendida' => ['required', 'date', 'after_or_equal:today'],
            'prioridade' => ['required', Rule::in([1, 2, 3])],
            'vinculadas' => ['nullable', 'array', 'max:10'],
            'vinculadas.*' => ['integer', Rule::exists('demandas', 'id')->where('orgao_id', $orgaoId)],
            'excecao_prazo' => ['nullable', Rule::in(array_keys(PrazoDemanda::EXCECOES))],
            'justificativa_excecao' => ['nullable', 'string', 'max:5000'],
        ], [
            'justificativa.min' => 'Explique a necessidade com pelo menos 30 caracteres: ela fundamenta o processo.',
            'quantidade.required' => 'Informe a quantidade estimada.',
            'valor_estimado.required' => 'Informe o valor estimado preliminar.',
            'data_pretendida.required' => 'Informe para quando a contratação é necessária.',
        ]);

        // Data-limite do órgão para o exercício: depois dela, só as exceções justificadas.
        $validador->after(function (Validator $v) use ($request) {
            $prazo = PrazoDfd::where('exercicio', $request->integer('exercicio'))->first();

            foreach (PrazoDemanda::motivos($prazo?->data_limite, today(), $request->integer('exercicio'), $request->input('excecao_prazo'), $request->input('justificativa_excecao')) as $m) {
                $v->errors()->add($request->filled('excecao_prazo') ? 'justificativa_excecao' : 'excecao_prazo', $m);
            }
        });

        $dados = $validador->validate();
        $vinculadas = $dados['vinculadas'] ?? [];
        unset($dados['vinculadas']);

        if (! ($dados['excecao_prazo'] ?? null)) {
            $dados['justificativa_excecao'] = null;
        }
        $seq = Demanda::where('ano', $ano)->count() + 1;

        $demanda = Demanda::create($dados + [
            'orgao_id' => $orgaoId,
            'solicitante_id' => $request->user()->id,
            'ano' => $ano,
            'numero' => str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            'situacao' => 'enviada',
        ]);
        $demanda->vinculadas()->attach(collect($vinculadas)->unique()->mapWithKeys(fn ($id) => [$id => ['orgao_id' => $orgaoId]])->all());

        return redirect()->route('demandas.index')->with('sucesso', "Demanda {$demanda->numero}/{$demanda->ano} enviada ao planejamento.");
    }

    /** Data-limite para envio de demandas ao PCA de um exercício (art. 12, VII). */
    public function definirPrazo(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422, 'O prazo é definido por usuário de um órgão.');
        $ano = (int) now()->format('Y');

        $dados = $request->validate([
            'exercicio' => ['required', 'integer', Rule::in([$ano, $ano + 1])],
            'data_limite' => ['required', 'date'],
        ]);

        PrazoDfd::updateOrCreate(['orgao_id' => $orgaoId, 'exercicio' => $dados['exercicio']], ['data_limite' => $dados['data_limite'], 'definido_por' => $request->user()->id]);

        return back()->with('sucesso', "Prazo para demandas do PCA {$dados['exercicio']} definido até ".Carbon::parse($dados['data_limite'])->format('d/m/Y').'. Depois dele, só entram as exceções justificadas.');
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
