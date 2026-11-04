<?php

namespace App\Http\Controllers;

use App\Licitacao\Pca\RegrasItemPca;
use App\Licitacao\Pca\RevisaoPca;
use App\Licitacao\Regras\VersoesPca;
use App\Licitacao\RegraViolada;
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
            'importacoes' => $plano->importacoes()->with('usuario')->limit(10)->get(),
        ]);
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
