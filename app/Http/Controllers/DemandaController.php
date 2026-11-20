<?php

namespace App\Http\Controllers;

use App\Licitacao\Pca\RegrasItemPca;
use App\Licitacao\Regras\PrazoDemanda;
use App\Models\Demanda;
use App\Models\ItemPca;
use App\Models\PrazoDfd;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/** Documento de formalização da demanda (DFD), feito pela área requisitante. */
class DemandaController extends Controller
{
    public function index(Request $request): View
    {
        $demandas = Demanda::with(['unidade', 'solicitante', 'itemPca', 'vinculadas', 'itens'])
            ->when($request->filled('situacao'), fn ($q) => $q->where('situacao', $request->string('situacao')))
            ->when(! $request->user()->can('pca.gerenciar'), fn ($q) => $q->where('solicitante_id', $request->user()->id))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('demandas.index', ['demandas' => $demandas, 'prazos' => PrazoDfd::orderByDesc('exercicio')->limit(5)->get()]);
    }

    public function create(): View
    {
        return view('demandas.form', $this->dadosFormulario(null));
    }

    public function edit(Request $request, Demanda $demanda): View
    {
        $this->autorizarEdicao($request, $demanda);

        return view('demandas.form', $this->dadosFormulario($demanda->load('itens', 'vinculadas')));
    }

    /** @return array<string, mixed> */
    private function dadosFormulario(?Demanda $demanda): array
    {
        $ano = (int) now()->format('Y');
        $prazos = PrazoDfd::whereIn('exercicio', [$ano, $ano + 1])->get()->keyBy('exercicio');
        // Padrão: o próximo exercício enquanto o prazo dele estiver aberto (as demandas do ano seguinte se planejam antes).
        $padrao = ($p = $prazos->get($ano + 1)) && ! PrazoDemanda::encerrado($p->data_limite, today()) ? $ano + 1 : $ano;

        return [
            'demanda' => $demanda,
            'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(),
            'itensPca' => ItemPca::whereHas('plano', fn ($q) => $q->whereIn('ano', [$ano, $ano + 1]))->orderBy('numero')->get(),
            'exercicios' => [$ano, $ano + 1],
            'exercicioPadrao' => $demanda?->exercicio ?? $padrao,
            'prazos' => $prazos,
            'outrasDemandas' => Demanda::where('situacao', '!=', 'recusada')->when($demanda, fn ($q) => $q->whereKeyNot($demanda->id))->latest('id')->limit(200)->get(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422, 'Demanda é registrada por usuário de um órgão.');

        return $this->salvar($request, null);
    }

    public function update(Request $request, Demanda $demanda): RedirectResponse
    {
        $this->autorizarEdicao($request, $demanda);

        return $this->salvar($request, $demanda);
    }

    /** Envia ao planejamento o DFD salvo em elaboração ou corrigido depois da devolução. */
    public function enviar(Request $request, Demanda $demanda): RedirectResponse
    {
        $this->autorizarEdicao($request, $demanda);

        if ($demanda->itens()->doesntExist()) {
            return back()->with('erro', 'Inclua ao menos um item do catálogo antes de enviar o DFD.');
        }
        if ($motivos = PrazoDemanda::motivos(PrazoDfd::where('exercicio', $demanda->exercicio)->first()?->data_limite, today(), (int) $demanda->exercicio, $demanda->excecao_prazo, $demanda->justificativa_excecao)) {
            return back()->with('erro', implode(' ', $motivos).' Edite o DFD e informe a exceção.');
        }

        $demanda->update(['situacao' => 'enviada', 'enviada_em' => now(), 'motivo_recusa' => null]);

        return back()->with('sucesso', "DFD {$demanda->numero}/{$demanda->ano} enviado ao planejamento.");
    }

    /** Só quem fez o DFD, ou o planejamento, altera; e só em elaboração ou devolvido. */
    private function autorizarEdicao(Request $request, Demanda $demanda): void
    {
        abort_unless($demanda->solicitante_id === $request->user()->id || $request->user()->can('pca.gerenciar'), 403);
        abort_unless($demanda->editavel(), 422, 'O DFD já foi enviado ao planejamento.');
    }

    private function salvar(Request $request, ?Demanda $demanda): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id ?? $demanda?->orgao_id;
        $enviar = $request->input('acao', 'enviar') !== 'rascunho';

        // Linhas em branco do quadro de itens são ignoradas; valores no formato brasileiro.
        $itens = collect((array) $request->input('itens', []))
            ->filter(fn ($i) => is_array($i) && (trim((string) ($i['descricao'] ?? '')) !== '' || trim((string) ($i['codigo_catalogo'] ?? '')) !== ''))
            ->map(fn ($i) => array_merge($i, ['quantidade' => decimal_br($i['quantidade'] ?? null), 'valor_unitario_estimado' => decimal_br($i['valor_unitario_estimado'] ?? null)]))
            ->values()->all();
        $request->merge(['itens' => $itens]);

        $ano = (int) now()->format('Y');
        $exercicios = array_values(array_unique(array_filter([$ano, $ano + 1, $demanda?->exercicio])));
        $regras = [
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('orgao_id', $orgaoId)],
            'item_pca_id' => ['nullable', 'integer', Rule::exists('itens_pca', 'id')->where('orgao_id', $orgaoId)],
            'exercicio' => ['required', 'integer', Rule::in($exercicios)],
            'objeto' => ['required', 'string', 'max:1000'],
            'justificativa' => ['required', 'string', 'min:30', 'max:10000'],
            // Campos obrigatórios do DFD: itens com quantidade e valor preliminar, data pretendida e prioridade (art. 12, VII).
            'itens' => ['required', 'array', 'min:1', 'max:200'],
            'data_pretendida' => ['required', 'date', 'after_or_equal:today'],
            'prioridade' => ['required', Rule::in([1, 2, 3])],
            'vinculadas' => ['nullable', 'array', 'max:10'],
            'vinculadas.*' => ['integer', Rule::exists('demandas', 'id')->where('orgao_id', $orgaoId)],
            'excecao_prazo' => ['nullable', Rule::in(array_keys(PrazoDemanda::EXCECOES))],
            'justificativa_excecao' => ['nullable', 'string', 'max:5000'],
        ];
        foreach ($itens as $n => $i) {
            foreach (RegrasItemPca::regras((int) $orgaoId, $i['material_servico'] ?? null) as $campo => $r) {
                if (in_array($campo, ['material_servico', 'categoria', 'codigo_catalogo', 'descricao', 'unidade_fornecimento', 'quantidade', 'valor_unitario_estimado'], true)) {
                    $regras["itens.{$n}.{$campo}"] = $r;
                }
            }
        }

        $validador = validator($request->all(), $regras, [
            'justificativa.min' => 'Explique a necessidade com pelo menos 30 caracteres: ela fundamenta o processo.',
            'itens.required' => 'Inclua ao menos um item, de preferência pelo catálogo CATMAT/CATSER.',
            'itens.*.codigo_catalogo.regex' => 'Use só os números do código CATMAT ou CATSER.',
            'itens.*.quantidade.required' => 'Informe a quantidade do item.',
            'itens.*.valor_unitario_estimado.required' => 'Informe o valor unitário estimado do item.',
            'data_pretendida.required' => 'Informe para quando a contratação é necessária.',
        ], collect($itens)->keys()->flatMap(fn ($n) => [
            "itens.{$n}.descricao" => 'descrição do item '.($n + 1), "itens.{$n}.quantidade" => 'quantidade do item '.($n + 1),
            "itens.{$n}.valor_unitario_estimado" => 'valor unitário do item '.($n + 1), "itens.{$n}.unidade_fornecimento" => 'unidade do item '.($n + 1),
            "itens.{$n}.codigo_catalogo" => 'código do item '.($n + 1),
        ])->all());

        // Data-limite do órgão para o exercício: depois dela, só as exceções justificadas. Vale no envio.
        $validador->after(function (Validator $v) use ($request, $enviar) {
            if (! $enviar) {
                return;
            }
            $prazo = PrazoDfd::where('exercicio', $request->integer('exercicio'))->first();

            foreach (PrazoDemanda::motivos($prazo?->data_limite, today(), $request->integer('exercicio'), $request->input('excecao_prazo'), $request->input('justificativa_excecao')) as $m) {
                $v->errors()->add($request->filled('excecao_prazo') ? 'justificativa_excecao' : 'excecao_prazo', $m);
            }
        });

        $dados = $validador->validate();
        $vinculadas = $dados['vinculadas'] ?? [];
        $linhas = $dados['itens'];
        unset($dados['vinculadas'], $dados['itens']);

        if (! ($dados['excecao_prazo'] ?? null)) {
            $dados['justificativa_excecao'] = null;
        }
        $dados['quantidade'] = round(array_sum(array_column($linhas, 'quantidade')), 4);
        $dados['valor_estimado'] = round(array_sum(array_map(fn ($i) => round($i['quantidade'] * $i['valor_unitario_estimado'], 2), $linhas)), 2);
        $dados += $enviar ? ['situacao' => 'enviada', 'enviada_em' => now(), 'motivo_recusa' => null] : ['situacao' => $demanda?->situacao ?? 'rascunho'];

        $demanda = DB::transaction(function () use ($demanda, $dados, $linhas, $vinculadas, $orgaoId, $ano, $request) {
            if ($demanda) {
                $demanda->update($dados);
                $demanda->itens()->delete();
            } else {
                $seq = Demanda::where('ano', $ano)->count() + 1;
                $demanda = Demanda::create($dados + ['orgao_id' => $orgaoId, 'solicitante_id' => $request->user()->id, 'ano' => $ano, 'numero' => str_pad((string) $seq, 4, '0', STR_PAD_LEFT)]);
            }

            foreach ($linhas as $n => $i) {
                $demanda->itens()->create([
                    'orgao_id' => $demanda->orgao_id, 'numero' => $n + 1, 'material_servico' => $i['material_servico'], 'categoria' => (int) $i['categoria'],
                    'codigo_catalogo' => ($i['codigo_catalogo'] ?? null) ?: null, 'descricao' => $i['descricao'], 'unidade_fornecimento' => mb_strtoupper(trim($i['unidade_fornecimento'])),
                    'quantidade' => $i['quantidade'], 'valor_unitario_estimado' => $i['valor_unitario_estimado'],
                ]);
            }
            $demanda->vinculadas()->sync(collect($vinculadas)->unique()->mapWithKeys(fn ($id) => [$id => ['orgao_id' => $demanda->orgao_id]])->all());

            return $demanda;
        });

        return redirect()->route('demandas.index')->with('sucesso', $enviar
            ? "DFD {$demanda->numero}/{$demanda->ano} enviado ao planejamento."
            : "DFD {$demanda->numero}/{$demanda->ano} salvo em elaboração. Envie ao planejamento quando estiver pronto.");
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
        abort_unless($demanda->situacao === 'enviada', 422, 'Só o DFD em análise recebe decisão.');

        $demanda->update(['situacao' => $dados['decisao'], 'motivo_recusa' => $dados['motivo_recusa'] ?? null]);

        return back()->with('sucesso', $dados['decisao'] === 'aceita' ? 'DFD aprovado. Ele entra no PCA na consolidação, ou você já pode abrir o processo a partir dele.' : 'Demanda devolvida ao requisitante.');
    }
}
