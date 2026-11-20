<?php

namespace App\Http\Controllers;

use App\Licitacao\EditalCompleto;
use App\Licitacao\Fluxo\FluxoDocumento;
use App\Licitacao\Ia\AssistenteIa;
use App\Licitacao\Ia\IaIndisponivel;
use App\Licitacao\Regras\MontagemEdital;
use App\Licitacao\RegraViolada;
use App\Models\Processo;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * "Gerar edital completo" na peça Minuta do edital (art. 25): monta o texto com os dados do processo, do ETP, do TR e
 * do contrato, deixa a IA propor o que faltar e gera o caderno do edital com os anexos. O texto volta para a tela
 * sem ser salvo: quem revisa e conclui a peça é o servidor.
 */
class EditalCompletoController extends Controller
{
    public function __construct(private EditalCompleto $edital, private FluxoDocumento $fluxo) {}

    public function gerar(Request $request, Processo $processo): RedirectResponse
    {
        $this->exigirElaborar($request, $processo);
        $d = $this->validar($request);
        $modelo = $this->edital->modelos($processo)->firstWhere('id', (int) $d['modelo_id']) ?? abort(422, 'Modelo de edital indisponível para este processo.');

        $r = $this->edital->montar($processo, $modelo, $d['edital'] ?? []);

        return redirect()->route('documentos.edit', [$processo, 'minuta_edital'])
            ->withInput(['conteudo' => ['texto' => $r['texto']], 'modelo_id' => $modelo->id, 'edital' => $d['edital'] ?? []])
            ->with('edital_gerado', ['modelo' => $modelo->titulo, 'pendentes' => $r['pendentes'], 'alertas' => $r['alertas']])
            ->with('sucesso', 'Edital montado na tela a partir do modelo "'.$modelo->titulo.'". Revise e salve: nada foi gravado ainda.');
    }

    public function propor(Request $request, Processo $processo, AssistenteIa $ia): JsonResponse
    {
        $u = $request->user();
        if (! $this->fluxo->podeElaborar($u, $processo, 'minuta_edital')) {
            return response()->json(['erro' => 'Você não pode elaborar esta peça agora.'], 403);
        }
        if (AssistenteIa::limiteAtingido($u)) {
            return response()->json(['erro' => 'Você chegou ao limite diário de uso da IA. Amanhã o limite renova.'], 429);
        }
        $d = $request->validate(['edital' => ['nullable', 'array'], 'edital.*' => ['nullable', 'string', 'max:4000']]);

        try {
            $proposta = $ia->proporEdital($processo, $d['edital'] ?? [], $this->edital->ehEngenharia($processo), $processo->matrizDeRiscosObrigatoria(), $u);
        } catch (RegraViolada $e) {
            return response()->json(['erro' => implode(' ', $e->motivos)], 422);
        } catch (IaIndisponivel $e) {
            return response()->json(['erro' => $e->getMessage()], 503);
        }

        return response()->json(['proposta' => $proposta, 'aviso' => 'Proposta da NexMind IA. Só os campos vazios foram preenchidos; confira cada um antes de gerar o edital.']);
    }

    /** Caderno do edital: edital e anexos (TR, minuta do contrato, mapa de riscos e declarações) num PDF único e paginado. */
    public function caderno(Processo $processo): Response
    {
        $pdf = $this->edital->pdf($processo);
        Auditoria::registrar('exportacao', $processo, "Caderno do edital do processo {$processo->identificacao()} gerado em PDF");

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug('edital-'.$processo->identificacao()).'.pdf"',
        ]);
    }

    private function exigirElaborar(Request $request, Processo $processo): void
    {
        abort_unless($this->fluxo->podeElaborar($request->user(), $processo, 'minuta_edital'), 403);
        $atual = $processo->documentoAtual('minuta_edital');
        abort_if($atual?->emTramitacao(), 409, 'A minuta do edital está em tramitação. Peça a devolução para ajustes.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        return $request->validate([
            'modelo_id' => ['required', 'integer'],
            'edital' => ['nullable', 'array'],
            'edital.sessao_em' => ['nullable', 'date'],
            'edital.local' => ['nullable', 'string', 'max:300'],
            'edital.prazo_assinatura' => ['nullable', 'integer', 'min:1', 'max:30'],
            'edital.garantia_pct' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'edital.garantia_justificativa' => ['nullable', 'string', 'max:1000'],
            'edital.habilitacao' => ['nullable', 'string', 'max:4000'],
            'edital.regime' => ['nullable', Rule::in(array_keys(MontagemEdital::REGIMES))],
            'edital.visita' => ['nullable', Rule::in(['facultativa', 'obrigatoria'])],
            'edital.visita_justificativa' => ['nullable', 'string', 'max:1000'],
        ], ['modelo_id.required' => 'Escolha o modelo de edital.', 'edital.prazo_assinatura.max' => 'O prazo para assinar vai de 1 a 30 dias úteis.']);
    }
}
