<?php

namespace App\Http\Controllers;

use App\Licitacao\DuplicacaoProcesso;
use App\Licitacao\Enums\AmparoLegal;
use App\Licitacao\Enums\CategoriaProcesso;
use App\Licitacao\Enums\CriterioJulgamento;
use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Enums\ModoDisputa;
use App\Licitacao\Enums\SituacaoProcesso;
use App\Licitacao\Fluxo\FluxoDocumento;
use App\Licitacao\Regras\InstrucaoProcesso;
use App\Licitacao\Regras\LimiteDispensa;
use App\Licitacao\Regras\PesquisaPrecos;
use App\Licitacao\Regras\PrevisaoPca;
use App\Licitacao\RegraViolada;
use App\Licitacao\TiposDocumento;
use App\Licitacao\TramitacaoProcesso;
use App\Licitacao\TramitacaoUnidades;
use App\Models\AnotacaoProcesso;
use App\Models\Demanda;
use App\Models\Fluxo;
use App\Models\Fornecedor;
use App\Models\ItemPca;
use App\Models\Processo;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;
use RuntimeException;

class ProcessoController extends Controller
{
    public function index(Request $request): View
    {
        $processos = Processo::with('unidade')
            ->when($request->filled('situacao'), fn ($q) => $q->where('situacao', $request->string('situacao')))
            ->when($request->filled('modalidade'), fn ($q) => $q->where('modalidade', $request->integer('modalidade')))
            ->when($request->filled('busca'), function ($q) use ($request) {
                $termo = '%'.mb_strtolower($request->string('busca')).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(objeto) like ?', [$termo])->orWhere('numero', 'like', $termo));
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('processos.index', ['processos' => $processos]);
    }

    public function create(Request $request): View
    {
        $demanda = $request->integer('demanda') ? Demanda::findOrFail($request->integer('demanda')) : null;

        $processo = new Processo([
            'ano' => (int) now()->format('Y'),
            'objeto' => $demanda?->objeto,
            'unidade_id' => $demanda?->unidade_id,
            'demanda_id' => $demanda?->id,
            'item_pca_id' => $demanda?->item_pca_id,
            'modalidade' => Modalidade::PregaoEletronico->value,
            'amparo_legal' => AmparoLegal::Art28I->value,
            'criterio_julgamento' => CriterioJulgamento::MenorPreco->value,
            'modo_disputa' => ModoDisputa::Aberto->value,
            'categoria' => CategoriaProcesso::Compras->value,
            'natureza_despesa' => $demanda?->itemPca?->natureza_despesa,
        ]);

        return view('processos.form', $this->dadosFormulario($processo));
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422, 'Processo é aberto por usuário de um órgão.');

        $dados = $this->validar($request, $orgaoId);
        $processo = Processo::create($dados + ['orgao_id' => $orgaoId]);
        $processo->forceFill(['situacao' => SituacaoProcesso::Planejamento->value])->save();

        if ($processo->demanda) {
            $processo->demanda->update(['situacao' => 'atendida']);
        }

        app(FluxoDocumento::class)->aplicar($processo); // fluxo padrão do órgão, se houver

        return redirect()->route('processos.show', $processo)->with('sucesso', "Processo {$processo->identificacao()} aberto. Agora instrua a fase preparatória.");
    }

    public function edit(Processo $processo): View|RedirectResponse
    {
        if (! $processo->editavel()) {
            return redirect()->route('processos.show', $processo)->with('erro', 'Depois de sair da fase preparatória, os dados do processo não mudam por aqui.');
        }

        return view('processos.form', $this->dadosFormulario($processo));
    }

    public function update(Request $request, Processo $processo): RedirectResponse
    {
        if (! $processo->editavel()) {
            return redirect()->route('processos.show', $processo)->with('erro', 'O processo não está mais na fase preparatória.');
        }

        $processo->update($this->validar($request, $processo->orgao_id, $processo));

        return redirect()->route('processos.show', $processo)->with('sucesso', 'Dados do processo atualizados.');
    }

    public function show(Request $request, Processo $processo, InstrucaoProcesso $instrucao, TramitacaoProcesso $tramitacao, LimiteDispensa $limite): View
    {
        $processo->load(['itens.cotacoes', 'itens.vencedor', 'unidade', 'responsavel', 'eventos.usuario', 'enviosPncp', 'contratos', 'demanda', 'itemPca.plano', 'origem', 'unidadeAtual']);
        $aba = in_array($request->query('aba'), ['instrucao', 'itens', 'resultado', 'historico', 'pncp', 'anotacoes', 'tramitacao'], true) ? $request->query('aba') : 'resumo';
        $pesquisa = new PesquisaPrecos;

        $precos = $processo->itens->mapWithKeys(fn ($item) => [$item->id => $pesquisa->calcular(
            $item->cotacoes->map(fn ($c) => ['valor' => $c->valor_unitario, 'fonte' => $c->fonte, 'data' => $c->data_cotacao, 'desconsiderada' => $c->desconsiderada, 'motivo' => $c->motivo_desconsideracao])->values()->all(),
            $item->metodo_preco,
        )]);

        $dispensa = null;
        if ($processo->amparoEnum()->ehDispensaPorValor()) {
            try {
                $dispensa = $limite->verificar($processo->orgao, $processo->ano, $processo->amparoEnum(), (float) $processo->valor_estimado, $processo->natureza_despesa, $processo->id);
            } catch (RuntimeException $e) {
                $dispensa = ['erro' => $e->getMessage()];
            }
        }

        return view('processos.show', [
            'processo' => $processo,
            'aba' => $aba,
            'checklist' => $instrucao->checklist($processo),
            'estados' => collect(array_keys(TiposDocumento::todos()))->mapWithKeys(fn ($t) => [$t => app(FluxoDocumento::class)->estado($processo, $t)])->all(),
            'etapas' => $processo->etapas()->get()->keyBy('tipo'),
            'fluxos' => $aba === 'instrucao' && $processo->editavel() ? Fluxo::where('ativo', true)->orderBy('nome')->get() : collect(),
            'destinos' => $tramitacao->destinosPara($processo, $request->user()),
            'precos' => $precos,
            'dispensa' => $dispensa,
            'tipos' => TiposDocumento::todos(),
            'documentos' => $processo->documentos()->orderByDesc('versao')->get()->unique('tipo')->keyBy('tipo'),
            'tramites' => $aba === 'tramitacao' ? $processo->tramites()->with(['origem', 'destino', 'remetente', 'recebedor', 'cancelador'])->get() : collect(),
            'periodos' => $aba === 'tramitacao' ? app(TramitacaoUnidades::class)->periodos($processo) : [],
            'unidadesAtivas' => $aba === 'tramitacao' ? Unidade::where('ativo', true)->orderBy('sigla')->get() : collect(),
            'remessaPendente' => $processo->remessaPendente(),
            'anotacoes' => $aba === 'anotacoes' ? $processo->anotacoes()->with('autor')->get() : collect(),
            'totalAnotacoes' => $processo->anotacoes()->count(),
            'podeAnotar' => AnotacaoProcesso::podeEscrever($request->user()),
            'fornecedores' => $aba === 'resultado' ? Fornecedor::where('ativo', true)->orderBy('razao_social')->get() : collect(),
        ]);
    }

    /** Duplica o processo para reaproveitar a instrução (contratação deserta, fracassada ou recorrente). */
    public function duplicar(Request $request, Processo $processo, DuplicacaoProcesso $duplicacao): RedirectResponse
    {
        $dados = $request->validate([
            'ano' => ['required', 'integer', 'min:2021', 'max:2100'],
            'numero' => ['required', 'string', 'max:30', Rule::unique('processos', 'numero')->where('orgao_id', $processo->orgao_id)->where('ano', $request->integer('ano'))],
        ], ['numero.unique' => 'Já existe processo com esse número neste ano.']);

        $novo = $duplicacao->duplicar($processo, $request->user(), $dados['numero'], (int) $dados['ano']);

        return redirect()->route('processos.show', $novo)->with('sucesso', "Processo {$novo->identificacao()} criado a partir do {$processo->identificacao()}. Confira os preços (cotações vencidas saem do cálculo, art. 23, § 1º), revise as peças e indique a previsão no PCA.");
    }

    public function tramitar(Request $request, Processo $processo, TramitacaoProcesso $tramitacao): RedirectResponse
    {
        $dados = $request->validate([
            'destino' => ['required', Rule::enum(SituacaoProcesso::class)],
            'justificativa' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $tramitacao->transitar($processo, $dados['destino'], $request->user(), $dados['justificativa'] ?? null);
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível avançar.')->with('motivos', $e->motivos);
        }

        $processo->refresh();
        $msg = "Processo agora está em: {$processo->situacaoEnum()->rotulo()}.";

        if ($processo->enviosPncp()->where('created_at', '>=', now()->subMinute())->exists()) {
            $msg .= ' O envio ao PNCP foi para a fila.';
        }

        return redirect()->route('processos.show', $processo)->with('sucesso', $msg);
    }

    /** @return array<string, mixed> */
    private function dadosFormulario(Processo $processo): array
    {
        $ano = (int) old('ano', $processo->ano ?: now()->format('Y'));
        $itensPca = $this->itensPcaVigentes($ano);

        return [
            'processo' => $processo,
            'anoPca' => $ano,
            'itensPca' => $itensPca,
            // Cruzamento automático do objeto com o PCA vigente (art. 12, VII): só sugere, quem escolhe é o servidor.
            'sugestoesPca' => filled($processo->objeto) && ! $processo->item_pca_id
                ? PrevisaoPca::sugerir((string) $processo->objeto, $itensPca->map(fn ($i) => ['id' => $i->id, 'descricao' => $i->descricao]))
                : [],
            'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(),
            'responsaveis' => User::where('orgao_id', $processo->orgao_id ?? auth()->user()->orgao_id)->where('ativo', true)->orderBy('name')->get(),
        ];
    }

    /**
     * Itens do PCA do exercício com versão aprovada (a vigente vale até a nova aprovação, art. 12, § 1º).
     *
     * @return Collection<int, ItemPca>
     */
    private function itensPcaVigentes(int $ano): Collection
    {
        return ItemPca::whereHas('plano', fn ($q) => $q->where('ano', $ano)->where('versao_atual', '>', 0))->orderBy('numero')->get();
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, int $orgaoId, ?Processo $processo = null): array
    {
        $validador = validator($request->all(), [
            'numero' => ['required', 'string', 'max:30', Rule::unique('processos', 'numero')->where('orgao_id', $orgaoId)->where('ano', $request->integer('ano'))->ignore($processo)],
            'ano' => ['required', 'integer', 'min:2023', 'max:2100'],
            'numero_compra' => ['nullable', 'string', 'max:30'],
            'objeto' => ['required', 'string', 'min:15', 'max:5120'],
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('orgao_id', $orgaoId)],
            'responsavel_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('orgao_id', $orgaoId)],
            'demanda_id' => ['nullable', 'integer', Rule::exists('demandas', 'id')->where('orgao_id', $orgaoId)],
            'item_pca_id' => ['nullable', 'integer', Rule::exists('itens_pca', 'id')->where('orgao_id', $orgaoId)],
            'justificativa_fora_pca' => ['nullable', 'string', 'max:5000'],
            'modalidade' => ['required', Rule::enum(Modalidade::class)],
            'amparo_legal' => ['required', Rule::enum(AmparoLegal::class)],
            'criterio_julgamento' => ['required', Rule::enum(CriterioJulgamento::class)],
            'modo_disputa' => ['required', Rule::enum(ModoDisputa::class)],
            'categoria' => ['required', Rule::enum(CategoriaProcesso::class)],
            'natureza_despesa' => ['nullable', 'string', 'max:40'],
            'abertura_propostas_em' => ['nullable', 'date'],
            'encerramento_propostas_em' => ['nullable', 'date', 'after:abertura_propostas_em'],
        ], [
            'numero.unique' => 'Já existe processo com esse número neste ano.',
            'encerramento_propostas_em.after' => 'O encerramento das propostas precisa ser depois da abertura.',
        ]);

        $validador->after(function (Validator $v) use ($request) {
            // Trava de execução cruzada com o PCA (art. 12, VII, e art. 18, § 1º, II).
            $item = $request->integer('item_pca_id') ? ItemPca::with('plano')->find($request->integer('item_pca_id')) : null;
            $motivos = PrevisaoPca::motivos(
                $item !== null,
                $item?->plano?->versao_atual > 0,
                $item?->plano?->ano,
                $request->integer('ano'),
                $request->input('justificativa_fora_pca'),
            );

            foreach ($motivos as $motivo) {
                $v->errors()->add($item ? 'item_pca_id' : 'justificativa_fora_pca', $motivo);
            }

            $modalidade = Modalidade::tryFrom($request->integer('modalidade'));
            $amparo = AmparoLegal::tryFrom($request->integer('amparo_legal'));
            $categoria = CategoriaProcesso::tryFrom($request->integer('categoria'));

            if ($modalidade && $amparo && ! in_array($modalidade, $amparo->modalidadesCompativeis(), true)) {
                $v->errors()->add('amparo_legal', "O amparo \"{$amparo->rotulo()}\" não combina com {$modalidade->rotulo()}.");
            }

            if ($amparo && $categoria && $amparo->ehDispensaPorValor()) {
                $esperado = $categoria->ehEngenharia() ? AmparoLegal::Art75I : AmparoLegal::Art75II;

                if ($amparo !== $esperado) {
                    $v->errors()->add('amparo_legal', "Para {$categoria->rotulo()}, a dispensa por valor é a do {$esperado->rotulo()}.");
                }

                if (! $request->filled('natureza_despesa')) {
                    $v->errors()->add('natureza_despesa', 'Na dispensa por valor, informe a natureza do objeto: é ela que agrupa as compras para o controle de fracionamento (art. 75, § 1º).');
                }
            }
        });

        $dados = $validador->validate();

        if ($dados['item_pca_id'] ?? null) {
            $dados['justificativa_fora_pca'] = null; // previsto no plano: a justificativa não se aplica
        }

        $dados['srp'] = $request->boolean('srp');
        $dados['orcamento_sigiloso'] = $request->boolean('orcamento_sigiloso');
        $dados['com_disputa'] = $request->boolean('com_disputa');

        return $dados;
    }
}
