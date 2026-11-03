<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\GestaoReequilibrio;
use App\Licitacao\Contratos\GestaoRescisao;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Models\Reequilibrio;
use App\Models\Rescisao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Reequilíbrio econômico-financeiro (art. 124, II, d) e extinção do contrato (arts. 137 a 139). */
class CicloContratoController extends Controller
{
    public function __construct(private GestaoReequilibrio $reequilibrios, private GestaoRescisao $rescisoes) {}

    public function solicitarReequilibrio(Request $request, Contrato $contrato): RedirectResponse
    {
        $request->merge(['valor_pedido' => decimal_br($request->input('valor_pedido'))]);
        $d = $request->validate(['fundamento' => ['required', Rule::in(array_keys(Reequilibrio::FUNDAMENTOS))], 'motivo' => ['required', 'string', 'max:5000'], 'valor_pedido' => ['required', 'numeric', 'gt:0']]);

        return $this->tentar(fn () => $this->reequilibrios->solicitar($contrato, $d['fundamento'], $d['motivo'], (float) $d['valor_pedido'], $request->user()), 'Pedido de reequilíbrio registrado.');
    }

    public function decidirReequilibrio(Request $request, Contrato $contrato, Reequilibrio $reequilibrio): RedirectResponse
    {
        abort_unless($reequilibrio->contrato_id === $contrato->id, 404);
        $request->merge(['valor_concedido' => decimal_br($request->input('valor_concedido'))]);
        $d = $request->validate(['acao' => ['required', 'in:deferir,indeferir'], 'valor_concedido' => ['nullable', 'numeric'], 'parecer' => ['required', 'string', 'max:5000']]);

        return $this->tentar(fn () => $this->reequilibrios->decidir($reequilibrio, $d['acao'] === 'deferir', isset($d['valor_concedido']) ? (float) $d['valor_concedido'] : null, $d['parecer'], $request->user()), 'Decisão registrada.');
    }

    public function iniciarRescisao(Request $request, Contrato $contrato): RedirectResponse
    {
        $d = $request->validate([
            'modalidade' => ['required', Rule::in(array_keys(Rescisao::MODALIDADES))], 'motivo' => ['required', Rule::in(array_keys(Rescisao::MOTIVOS))],
            'fundamentacao' => ['required', 'string', 'max:5000'], 'notificado_em' => ['nullable', 'date'], 'defesa_ate' => ['nullable', 'date'],
        ]);

        return $this->tentar(fn () => $this->rescisoes->iniciar($contrato, $d['modalidade'], $d['motivo'], $d['fundamentacao'], $d['notificado_em'] ?? null, $d['defesa_ate'] ?? null, $request->user()), 'Extinção iniciada. Garanta o contraditório e a ampla defesa antes de concluir.');
    }

    public function defesa(Request $request, Contrato $contrato, Rescisao $rescisao): RedirectResponse
    {
        abort_unless($rescisao->contrato_id === $contrato->id, 404);
        $d = $request->validate(['defesa' => ['required', 'string', 'max:5000']]);

        return $this->tentar(fn () => $this->rescisoes->registrarDefesa($rescisao, $d['defesa']), 'Defesa registrada.');
    }

    public function concluirRescisao(Request $request, Contrato $contrato, Rescisao $rescisao): RedirectResponse
    {
        abort_unless($rescisao->contrato_id === $contrato->id, 404);
        $request->merge(['valor_indenizacao' => decimal_br($request->input('valor_indenizacao'))]);
        $d = $request->validate(['data_efeito' => ['required', 'date'], 'culpa_administracao' => ['nullable', 'boolean'], 'valor_indenizacao' => ['nullable', 'numeric', 'min:0']]);

        return $this->tentar(fn () => $this->rescisoes->concluir($rescisao, $d['data_efeito'], $request->boolean('culpa_administracao'), isset($d['valor_indenizacao']) ? (float) $d['valor_indenizacao'] : null, $request->user()), 'Contrato extinto. O termo de rescisão foi criado: publique no PNCP.');
    }

    public function cancelarRescisao(Contrato $contrato, Rescisao $rescisao): RedirectResponse
    {
        abort_unless($rescisao->contrato_id === $contrato->id, 404);

        return $this->tentar(fn () => $this->rescisoes->cancelar($rescisao), 'Extinção cancelada.');
    }

    private function tentar(callable $acao, string $ok): RedirectResponse
    {
        try {
            $acao();
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível concluir.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', $ok);
    }
}
