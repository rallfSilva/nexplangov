<?php

namespace App\Http\Controllers;

use App\Models\AnotacaoProcesso;
use App\Models\Processo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Anotações internas de rascunho entre os servidores que atuam no processo. Não compõem os autos: quem precisa
 * registrar algo oficial usa as peças do processo ou a justificativa da tramitação.
 */
class AnotacaoProcessoController extends Controller
{
    public function store(Request $request, Processo $processo): RedirectResponse
    {
        abort_unless(AnotacaoProcesso::podeEscrever($request->user()), 403);
        $dados = $request->validate(['texto' => ['required', 'string', 'max:5000']]);

        $processo->anotacoes()->create(['orgao_id' => $processo->orgao_id, 'user_id' => $request->user()->id, 'texto' => $dados['texto']]);

        return redirect()->route('processos.show', [$processo, 'aba' => 'anotacoes'])->with('sucesso', 'Anotação registrada.');
    }

    public function update(Request $request, Processo $processo, AnotacaoProcesso $anotacao): RedirectResponse
    {
        $this->exigirAutor($request, $processo, $anotacao);
        $anotacao->update($request->validate(['texto' => ['required', 'string', 'max:5000']]));

        return redirect()->route('processos.show', [$processo, 'aba' => 'anotacoes'])->with('sucesso', 'Anotação atualizada.');
    }

    public function destroy(Request $request, Processo $processo, AnotacaoProcesso $anotacao): RedirectResponse
    {
        $this->exigirAutor($request, $processo, $anotacao);
        $anotacao->delete();

        return redirect()->route('processos.show', [$processo, 'aba' => 'anotacoes'])->with('sucesso', 'Anotação excluída.');
    }

    /** Só o autor edita ou exclui a própria anotação. */
    private function exigirAutor(Request $request, Processo $processo, AnotacaoProcesso $anotacao): void
    {
        abort_unless($anotacao->processo_id === $processo->id, 404);
        abort_unless($anotacao->user_id === $request->user()->id, 403, 'Só quem escreveu a anotação pode alterá-la.');
    }
}
