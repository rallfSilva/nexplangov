<?php

namespace App\Http\Controllers;

use App\Models\CatalogoItem;
use App\Models\Contrato;
use App\Models\EnvioPncp;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\RegistroAuditoria;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\View\View;

/**
 * Painel master da NexMind: visão operacional de todos os órgãos. Só administrador geral (sem órgão),
 * por isso os escopos de órgão não filtram. Mostra contagens e saúde, nunca o conteúdo dos processos.
 */
class MasterController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(auth()->user()->orgao_id === null, 403);

        $usuarios = User::selectRaw('orgao_id, count(*) filter (where ativo) as ativos, count(*) filter (where ativo and two_factor_confirmed_at is null) as sem_2fa')->whereNotNull('orgao_id')->groupBy('orgao_id')->get()->keyBy('orgao_id');
        $processos = Processo::selectRaw('orgao_id, count(*) as total')->whereNotIn('situacao', ['contratado', 'revogado', 'anulado', 'deserto', 'fracassado'])->groupBy('orgao_id')->pluck('total', 'orgao_id');
        $contratos = Contrato::selectRaw('orgao_id, count(*) as total')->where('situacao', 'vigente')->groupBy('orgao_id')->pluck('total', 'orgao_id');
        $pncpErros = EnvioPncp::selectRaw('orgao_id, count(*) as total')->where('estado', 'erro')->groupBy('orgao_id')->pluck('total', 'orgao_id');
        $atividade = RegistroAuditoria::selectRaw('orgao_id, max(created_at) as ultima')->groupBy('orgao_id')->pluck('ultima', 'orgao_id');

        $linhas = Orgao::orderBy('sigla')->get()->map(fn ($o) => [
            'orgao' => $o, 'ativos' => (int) ($usuarios[$o->id]->ativos ?? 0), 'sem_2fa' => (int) ($usuarios[$o->id]->sem_2fa ?? 0),
            'processos' => (int) ($processos[$o->id] ?? 0), 'contratos' => (int) ($contratos[$o->id] ?? 0), 'pncp_erros' => (int) ($pncpErros[$o->id] ?? 0), 'ultima' => $atividade[$o->id] ?? null,
        ]);

        $quebra = Auditoria::verificarCadeia();

        return view('master', [
            'linhas' => $linhas, 'cadeiaIntegra' => $quebra === null, 'quebra' => $quebra,
            'registros' => RegistroAuditoria::count(),
            'catalogo' => ['M' => CatalogoItem::where('tipo', 'M')->count(), 'S' => CatalogoItem::where('tipo', 'S')->count()],
        ]);
    }
}
