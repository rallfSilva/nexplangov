<?php

namespace App\Http\Controllers;

use App\Licitacao\Alertas\Detector;
use App\Models\Contrato;
use App\Models\Demanda;
use App\Models\EnvioPncp;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Models\RegistroAuditoria;
use App\Models\SolicitacaoTitular;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PainelController extends Controller
{
    public function __construct(private Detector $detector) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $ano = (int) now()->format('Y');

        $pca = PlanoContratacao::where('ano', $ano)->withSum('itens', 'valor_total_estimado')->withCount('itens')->first();
        $porSituacao = Processo::selectRaw('situacao, count(*) as total, sum(valor_estimado) as valor')->where('ano', $ano)->groupBy('situacao')->get()->keyBy('situacao');

        return view('painel', [
            'ano' => $ano,
            'prazos' => array_slice($this->detector->paraUsuario($user), 0, 8),
            'pca' => $pca,
            'porSituacao' => $porSituacao,
            'emAndamento' => (int) $porSituacao->filter(fn ($s, $k) => in_array($k, ['planejamento', 'analise_juridica', 'aguardando_autorizacao', 'publicado', 'em_julgamento'], true))->sum('total'),
            'contratado' => (float) Contrato::where('ano', $ano)->sum('valor_global'),
            'vencendo' => Contrato::with('fornecedor')->where('situacao', 'vigente')->whereDate('vigencia_fim', '<=', today()->addDays(120))->orderBy('vigencia_fim')->limit(6)->get(),
            'demandasNovas' => $user->can('pca.gerenciar') ? Demanda::where('situacao', 'enviada')->count() : null,
            'pncpErros' => EnvioPncp::where('estado', 'erro')->count(),
            'lgpdAbertas' => $user->can('lgpd.gerenciar') ? SolicitacaoTitular::whereNotIn('situacao', ['concluida', 'indeferida'])->count() : null,
            'ultimosEventos' => $user->can('auditoria.ver') ? RegistroAuditoria::with('usuario')->latest('id')->limit(6)->get() : collect(),
            'meusProcessos' => Processo::where('responsavel_id', $user->id)->whereNotIn('situacao', ['contratado', 'revogado', 'anulado', 'deserto', 'fracassado'])->latest('id')->limit(5)->get(),
        ]);
    }
}
