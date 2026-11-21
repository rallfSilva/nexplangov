<?php

namespace App\Http\Controllers;

use App\Licitacao\Pca\PainelPca;
use App\Licitacao\Pca\RegrasItemPca;
use App\Licitacao\Pca\RevisaoPca;
use App\Licitacao\Regras\CalendarioPca;
use App\Licitacao\Regras\VersoesPca;
use App\Licitacao\RegraViolada;
use App\Models\EnvioPncp;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\PrazoDfd;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/** Plano de Contratações Anual (art. 12, VII, da Lei 14.133). */
class PcaController extends Controller
{
    public function index(Request $request): View
    {
        $planos = PlanoContratacao::orderByDesc('ano')
            ->when($request->filled('q'), fn ($q) => $q->where('ano', $request->integer('q')))
            ->get();

        return view('pca.index', [
            'planos' => $planos->map(fn (PlanoContratacao $p) => ['plano' => $p, 'resumo' => PainelPca::resumo($p)]),
            'proximoAno' => (int) now()->addYear()->format('Y'),
        ]);
    }

    public function create(): View
    {
        return view('pca.form', $this->dadosFormulario(null));
    }

    public function edit(PlanoContratacao $plano): View
    {
        return view('pca.form', $this->dadosFormulario($plano->load('participantes')));
    }

    /** @return array<string, mixed> */
    private function dadosFormulario(?PlanoContratacao $plano): array
    {
        return ['plano' => $plano, 'unidades' => Unidade::where('ativo', true)->orderBy('sigla')->get(), 'proximoAno' => (int) now()->addYear()->format('Y')];
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $this->orgaoDoUsuario($request);
        $dados = $this->validarCadastro($request, $orgaoId, null);

        $plano = DB::transaction(function () use ($dados, $orgaoId, $request) {
            $plano = PlanoContratacao::create(Arr::except($dados, ['participantes']) + ['orgao_id' => $orgaoId, 'situacao' => 'rascunho']);
            $this->salvarParticipantes($plano, $dados['participantes'] ?? []);
            $this->sincronizarPrazoDfd($plano, $request);

            return $plano;
        });

        return redirect()->route('pca.show', $plano)->with('sucesso', "PCA {$plano->ano} criado. As unidades já podem lançar os DFDs a partir de ".$plano->inicio_demandas->format('d/m/Y').'.');
    }

    public function update(Request $request, PlanoContratacao $plano): RedirectResponse
    {
        $dados = $this->validarCadastro($request, $plano->orgao_id, $plano);

        DB::transaction(function () use ($plano, $dados, $request) {
            $plano->update(Arr::except($dados, ['participantes', 'ano']));
            $this->salvarParticipantes($plano, $dados['participantes'] ?? []);
            $this->sincronizarPrazoDfd($plano, $request);
        });

        return redirect()->route('pca.show', $plano)->with('sucesso', "Cadastro do PCA {$plano->ano} atualizado.");
    }

    /** @return array<string, mixed> */
    private function validarCadastro(Request $request, int $orgaoId, ?PlanoContratacao $plano): array
    {
        $unidade = Rule::exists('unidades', 'id')->where('orgao_id', $orgaoId);
        $regras = [
            'abrangencia' => ['required', Rule::in(array_keys(PlanoContratacao::ABRANGENCIAS))],
            'possui_objetivo_estrategico' => ['required', 'boolean'],
            'objetivo_estrategico' => ['nullable', 'required_if:possui_objetivo_estrategico,1', 'string', 'max:5000'],
            'tipo_consolidacao' => ['required', Rule::in(array_keys(PlanoContratacao::TIPOS_CONSOLIDACAO))],
            'unidade_responsavel_id' => ['required', 'integer', $unidade],
            'nivel_detalhamento' => ['required', Rule::in(array_keys(PlanoContratacao::NIVEIS_DETALHAMENTO))],
            'participantes' => ['nullable', 'required_if:abrangencia,unidades', 'array', 'max:500'],
            'participantes.*' => ['integer', $unidade],
        ] + collect(CalendarioPca::ETAPAS)->mapWithKeys(fn ($r, $c) => [$c => ['required', 'date']])->all();
        if (! $plano) {
            $regras['ano'] = ['required', 'integer', 'min:2023', 'max:2100', Rule::unique('planos_contratacao', 'ano')->where('orgao_id', $orgaoId)];
        }

        $validador = validator($request->all(), $regras, [
            'ano.unique' => 'Já existe PCA para esse ano.',
            'objetivo_estrategico.required_if' => 'Descreva o objetivo estratégico a que o plano se vincula.',
            'participantes.required_if' => 'Escolha as unidades participantes.',
        ], collect(CalendarioPca::ETAPAS)->map(fn ($r) => mb_strtolower($r))->all() + ['unidade_responsavel_id' => 'unidade responsável']);

        // Calendário de elaboração em ordem, com divulgação até o fim do exercício (art. 12, VII, e § 1º).
        $validador->after(function (Validator $v) use ($request, $plano) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $datas = collect(CalendarioPca::ETAPAS)->mapWithKeys(fn ($r, $c) => [$c => Carbon::parse($request->input($c))->toDateString()])->all();
            foreach (CalendarioPca::motivos($datas, (int) ($plano->ano ?? $request->integer('ano'))) as $m) {
                $v->errors()->add('fim_divulgacao', $m);
            }
        });

        $dados = $validador->validate();
        if (! $dados['possui_objetivo_estrategico']) {
            $dados['objetivo_estrategico'] = null;
        }
        if ($dados['abrangencia'] === 'orgao') {
            $dados['participantes'] = [];
        }

        return $dados;
    }

    private function salvarParticipantes(PlanoContratacao $plano, array $ids): void
    {
        $plano->participantes()->sync(collect($ids)->map(fn ($id) => (int) $id)->unique()->mapWithKeys(fn ($id) => [$id => ['orgao_id' => $plano->orgao_id]])->all());
    }

    /** O fim do cadastro dos DFDs vira a data-limite de envio das demandas do exercício. */
    private function sincronizarPrazoDfd(PlanoContratacao $plano, Request $request): void
    {
        PrazoDfd::updateOrCreate(['orgao_id' => $plano->orgao_id, 'exercicio' => $plano->ano], ['data_limite' => $plano->fim_dfds, 'definido_por' => $request->user()->id]);
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
            'importacoes' => $plano->importacoes()->with('usuario')->limit(10)->get(),
            'publicacao' => $this->publicacaoPorUnidade($plano),
        ]);
    }

    /**
     * Situação no PNCP de cada unidade que publica parte do plano (art. 12, § 1º).
     *
     * @return list<array{unidade: ?Unidade, itens: int, valor: float, numero: ?string, envio: ?EnvioPncp}>
     */
    private function publicacaoPorUnidade(PlanoContratacao $plano): array
    {
        $grupos = $plano->gruposPncp()['grupos'];
        $partes = $plano->pcasUnidade()->with(['unidade', 'envios'])->get()->keyBy('unidade_id');
        $unidades = Unidade::whereIn('id', array_keys($grupos))->get()->keyBy('id');
        $itens = $plano->itens->keyBy('id');
        $linhas = [];

        foreach ($grupos as $unidadeId => $ids) {
            $parte = $partes->get($unidadeId);
            $linhas[] = ['unidade' => $unidades->get($unidadeId), 'itens' => count($ids), 'valor' => (float) $itens->only($ids)->sum('valor_total_estimado'),
                'numero' => $parte?->numero_controle_pncp, 'envio' => $parte?->envios->first()];
        }

        return $linhas;
    }

    public function salvarItem(Request $request, PlanoContratacao $plano, ?ItemPca $item = null): RedirectResponse
    {
        abort_if($item && $item->plano_contratacao_id !== $plano->id, 404);

        if (! $plano->editavel()) {
            return back()->with('erro', 'O PCA aprovado não pode ser alterado diretamente. Abra uma revisão do plano, com justificativa.');
        }

        $request->merge([
            'quantidade' => decimal_br($request->input('quantidade')),
            'valor_unitario_estimado' => decimal_br($request->input('valor_unitario_estimado')),
        ]);

        $dados = $request->validate(RegrasItemPca::regras($plano->orgao_id, $request->input('material_servico')), RegrasItemPca::mensagens());

        if ($item) {
            $item->update($dados);
            $msg = "Item {$item->numero} atualizado.";
        } else {
            $item = $plano->itens()->create($dados + ['orgao_id' => $plano->orgao_id, 'numero' => $plano->proximoNumeroItem()]);
            $msg = "Item {$item->numero} incluído no PCA.";
        }

        return redirect()->route('pca.show', $plano)->with('sucesso', $msg);
    }

    public function excluirItem(PlanoContratacao $plano, ItemPca $item): RedirectResponse
    {
        abort_if($item->plano_contratacao_id !== $plano->id, 404);

        if (! $plano->editavel()) {
            return back()->with('erro', 'O PCA aprovado não pode ser alterado diretamente. Abra uma revisão do plano, com justificativa.');
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

        return back()->with('sucesso', $plano->emRevisao()
            ? "Revisão do PCA {$plano->ano} enviada para aprovação da autoridade competente."
            : "PCA {$plano->ano} enviado para aprovação da autoridade competente.");
    }

    public function aprovar(Request $request, PlanoContratacao $plano, RevisaoPca $revisao): RedirectResponse
    {
        try {
            $versao = $revisao->aprovar($plano, $request->user());
        } catch (RegraViolada $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', "PCA {$plano->ano} aprovado (versão {$versao->numero}). Ele deve ser divulgado no sítio oficial e no PNCP (art. 12, § 1º).");
    }

    public function abrirRevisao(Request $request, PlanoContratacao $plano, RevisaoPca $revisao): RedirectResponse
    {
        $request->validate(['justificativa' => ['required', 'string', 'max:2000']], [], ['justificativa' => 'justificativa da revisão']);

        try {
            $revisao->abrir($plano, $request->user(), $request->input('justificativa'));
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', "Revisão aberta sobre a versão {$plano->versao_atual}. Altere os itens e envie de novo para aprovação.");
    }

    public function descartarRevisao(PlanoContratacao $plano, RevisaoPca $revisao): RedirectResponse
    {
        try {
            $revisao->descartar($plano);
        } catch (RegraViolada $e) {
            return back()->with('erro', $e->getMessage());
        }

        return back()->with('sucesso', "Revisão descartada. O PCA voltou à versão {$plano->versao_atual}.");
    }

    /** Versões aprovadas e o que mudou entre elas (ou entre a vigente e a revisão em andamento). */
    public function versoes(Request $request, PlanoContratacao $plano): View
    {
        $versoes = $plano->versoes()->with(['aprovador', 'autorRevisao'])->get();
        $opcoes = $versoes->pluck('numero')->all();
        if ($plano->emRevisao() || $plano->versao_atual === 0) {
            array_unshift($opcoes, 'atual');
        }

        $para = $request->query('para', $opcoes[0] ?? null);
        $para = in_array($para, $opcoes, false) ? $para : ($opcoes[0] ?? null);
        $padraoDe = $para === 'atual' ? $plano->versao_atual : ((int) $para - 1);
        $de = (int) $request->query('de', $padraoDe);

        $itensDe = $versoes->firstWhere('numero', $de)?->itens ?? [];
        $itensPara = $para === 'atual' ? $plano->copiaDosItens() : ($versoes->firstWhere('numero', (int) $para)?->itens ?? []);

        return view('pca.versoes', [
            'plano' => $plano, 'versoes' => $versoes, 'opcoes' => $opcoes, 'de' => $de, 'para' => $para,
            'diff' => $para === null ? null : VersoesPca::comparar($itensDe, $itensPara),
            'unidades' => Unidade::where('orgao_id', $plano->orgao_id)->pluck('sigla', 'id'),
        ]);
    }

    private function orgaoDoUsuario(Request $request): int
    {
        $orgaoId = $request->user()->orgao_id ?? $request->integer('orgao_id');
        abort_unless($orgaoId, 422, 'Escolha o órgão.');

        return $orgaoId;
    }
}
