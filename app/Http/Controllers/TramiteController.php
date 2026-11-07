<?php

namespace App\Http\Controllers;

use App\Licitacao\RegraViolada;
use App\Licitacao\TramitacaoUnidades;
use App\Models\Processo;
use App\Models\TramiteProcesso;
use App\Models\Unidade;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Remessa dos autos entre unidades, recebimento, cancelamento, arquivamento e a caixa "Meus processos". */
class TramiteController extends Controller
{
    public function __construct(private TramitacaoUnidades $tramitacao) {}

    public function enviar(Request $request, Processo $processo): RedirectResponse
    {
        $d = $request->validate(['unidade_destino_id' => ['nullable', 'integer'], 'despacho' => ['nullable', 'string', 'max:5000']]);
        $destino = isset($d['unidade_destino_id']) ? Unidade::where('ativo', true)->find($d['unidade_destino_id'])?->id : null;

        return $this->executar($processo, fn () => $this->tramitacao->enviar($processo, $request->user(), $destino, $d['despacho'] ?? null), 'Processo enviado. Ele aparece em "Meus processos" da unidade de destino até ser recebido.');
    }

    public function receber(Request $request, Processo $processo, TramiteProcesso $tramite): RedirectResponse
    {
        abort_unless($tramite->processo_id === $processo->id, 404);

        return $this->executar($processo, fn () => $this->tramitacao->receber($tramite, $request->user()), 'Processo recebido na sua unidade.');
    }

    public function cancelar(Request $request, Processo $processo, TramiteProcesso $tramite): RedirectResponse
    {
        abort_unless($tramite->processo_id === $processo->id, 404);
        $d = $request->validate(['motivo' => ['nullable', 'string', 'max:2000']]);

        return $this->executar($processo, fn () => $this->tramitacao->cancelar($tramite, $request->user(), $d['motivo'] ?? null), 'Remessa cancelada. O processo continua na unidade de origem.');
    }

    public function arquivar(Request $request, Processo $processo): RedirectResponse
    {
        $d = $request->validate(['despacho' => ['nullable', 'string', 'max:5000']]);

        return $this->executar($processo, fn () => $this->tramitacao->arquivar($processo, $request->user(), $d['despacho'] ?? null), 'Processo arquivado na unidade.');
    }

    public function desarquivar(Request $request, Processo $processo): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['nullable', 'string', 'max:2000']]);

        return $this->executar($processo, fn () => $this->tramitacao->desarquivar($processo, $request->user(), $d['motivo'] ?? null), 'Processo desarquivado. Ele volta a tramitar.');
    }

    /** Caixa de trabalho por unidade: a receber, na unidade, sob minha responsabilidade, enviados e arquivados. */
    public function meus(Request $request): View
    {
        $u = $request->user();
        $admin = $u->ehAdministradorGeral() || $u->hasRole('administrador-orgao');
        $unidades = Unidade::where('ativo', true)->orderBy('sigla')->get();
        $minhas = $u->unidadesDeAtuacao();

        $filtro = $request->integer('unidade') ?: null;
        $alvo = match (true) {
            $filtro !== null && ($admin || in_array($filtro, $minhas, true)) => [$filtro],
            $minhas !== [] => $minhas,
            $admin => $unidades->pluck('id')->all(),
            default => [],
        };

        $aReceber = TramiteProcesso::with(['processo', 'origem', 'destino', 'remetente'])->where('tipo', 'remessa')
            ->whereIn('unidade_destino_id', $alvo)->whereNull('recebido_em')->whereNull('cancelado_em')->orderBy('enviado_em')->get();

        $naUnidade = Processo::with(['unidadeAtual', 'responsavel'])->whereIn('unidade_atual_id', $alvo)->whereNull('arquivado_em')
            ->whereDoesntHave('tramites', fn ($q) => $q->where('tipo', 'remessa')->whereNull('recebido_em')->whereNull('cancelado_em'))
            ->orderByDesc('updated_at')->limit(200)->get();

        $chegadas = TramiteProcesso::where('tipo', 'remessa')->whereIn('processo_id', $naUnidade->pluck('id'))->whereNotNull('recebido_em')
            ->orderBy('recebido_em')->get()->groupBy('processo_id')->map(fn ($g) => $g->last()->recebido_em);

        return view('tramitacao.meus', [
            'unidades' => $unidades,
            'filtro' => $filtro,
            'minhas' => $minhas,
            'admin' => $admin,
            'aReceber' => $aReceber,
            'naUnidade' => $naUnidade,
            'chegadas' => $chegadas,
            'responsavel' => Processo::with('unidadeAtual')->where('responsavel_id', $u->id)->whereNull('arquivado_em')->orderByDesc('updated_at')->limit(100)->get(),
            'enviados' => TramiteProcesso::with(['processo', 'destino'])->where('tipo', 'remessa')->where('enviado_por', $u->id)->whereNull('recebido_em')->whereNull('cancelado_em')->orderByDesc('enviado_em')->get(),
            'arquivados' => Processo::with('unidadeAtual')->whereIn('unidade_atual_id', $alvo)->whereNotNull('arquivado_em')->orderByDesc('arquivado_em')->limit(100)->get(),
        ]);
    }

    private function executar(Processo $processo, callable $acao, string $sucesso): RedirectResponse
    {
        try {
            $acao();
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível concluir.')->with('motivos', $e->motivos);
        }

        return redirect()->route('processos.show', [$processo, 'aba' => 'tramitacao'])->with('sucesso', $sucesso);
    }
}
