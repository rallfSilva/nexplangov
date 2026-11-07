<?php

namespace App\Http\Controllers;

use App\Licitacao\Fluxo\FluxoDocumento;
use App\Licitacao\Ia\AssistenteIa;
use App\Licitacao\Ia\IaIndisponivel;
use App\Licitacao\RegraViolada;
use App\Models\Processo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** NexMind IA na tela da peça. Responde em JSON; nada é salvo na peça sem o usuário revisar. */
class IaController extends Controller
{
    public function __construct(private AssistenteIa $ia, private FluxoDocumento $fluxo) {}

    public function __invoke(Request $request, Processo $processo, string $tipo): JsonResponse
    {
        $d = $request->validate(['acao' => ['required', 'in:sugerir,revisar'], 'campo' => ['required_if:acao,sugerir', 'nullable', 'string', 'max:60'], 'conteudo' => ['nullable', 'array'], 'conteudo.*' => ['nullable', 'string', 'max:20000']]);
        $u = $request->user();

        if (! $this->fluxo->podeElaborar($u, $processo, $tipo)) {
            return response()->json(['erro' => 'Você não pode elaborar esta peça agora.'], 403);
        }
        if (AssistenteIa::limiteAtingido($u)) {
            return response()->json(['erro' => 'Você chegou ao limite diário de uso da IA. Amanhã o limite renova.'], 429);
        }

        try {
            $texto = $d['acao'] === 'sugerir'
                ? $this->ia->sugerir($processo, $tipo, $d['campo'], $d['conteudo'] ?? [], $u)
                : $this->ia->revisar($processo, $tipo, $d['conteudo'] ?? [], $u);
        } catch (RegraViolada $e) {
            return response()->json(['erro' => implode(' ', $e->motivos)], 422);
        } catch (IaIndisponivel $e) {
            return response()->json(['erro' => $e->getMessage()], 503);
        }

        return response()->json(['texto' => $texto, 'aviso' => 'Texto gerado por IA. Revise antes de salvar: a responsabilidade pela peça é de quem a assina.']);
    }
}
