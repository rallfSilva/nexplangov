<?php

namespace App\Http\Controllers;

use App\Models\Ata;
use App\Models\Contrato;
use App\Models\EnvioPncp;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Models\TermoContrato;
use App\Pncp\FilaPncp;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PncpController extends Controller
{
    public function index(Request $request): View
    {
        $envios = EnvioPncp::with(['publicavel', 'usuario'])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->string('estado')))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('pncp.index', [
            'envios' => $envios,
            'contagem' => EnvioPncp::selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado'),
        ]);
    }

    public function publicarContrato(Request $request, Contrato $contrato, FilaPncp $fila): RedirectResponse
    {
        $envio = $fila->agendar($contrato, 'contrato', 'inserir', $request->user());

        return back()->with($envio->estado === 'erro' ? 'erro' : 'sucesso', $envio->estado === 'erro' ? $envio->erro : 'Contrato na fila de publicação do PNCP.');
    }

    public function publicarTermo(Request $request, TermoContrato $termo, FilaPncp $fila): RedirectResponse
    {
        $envio = $fila->agendar($termo, 'termo', 'inserir', $request->user());

        return back()->with($envio->estado === 'erro' ? 'erro' : 'sucesso', $envio->estado === 'erro' ? $envio->erro : 'Termo na fila de publicação do PNCP.');
    }

    /** Ata de registro de preços (art. 82 e art. 174, § 2º). O PDF da ata segue depois do número. */
    public function publicarAta(Request $request, Ata $ata, FilaPncp $fila): RedirectResponse
    {
        return $this->resposta($fila->agendar($ata, 'ata', 'inserir', $request->user()), 'Ata na fila de publicação do PNCP.');
    }

    /** PCA aprovado (art. 12, § 1º). */
    public function publicarPca(Request $request, PlanoContratacao $plano, FilaPncp $fila): RedirectResponse
    {
        if ($plano->situacao !== 'aprovado') {
            return back()->with('erro', 'Só o PCA aprovado vai ao PNCP (art. 12, § 1º).');
        }

        return $this->resposta($fila->agendar($plano, 'pca', 'inserir', $request->user()), 'PCA na fila de publicação do PNCP.');
    }

    /** Resultado de cada item homologado da compra (art. 71, IV, e art. 174). */
    public function publicarResultados(Request $request, Processo $processo, FilaPncp $fila): RedirectResponse
    {
        $envios = $fila->agendarResultados($processo, $request->user());

        if ($envios === []) {
            return back()->with('erro', 'Nenhum item homologado com fornecedor vencedor para publicar.');
        }
        $erros = collect($envios)->where('estado', 'erro');

        return back()->with($erros->isEmpty() ? 'sucesso' : 'erro', $erros->isEmpty()
            ? count($envios).' resultado(s) na fila de publicação do PNCP.'
            : $erros->first()->erro);
    }

    /** Anexa o contrato assinado em PDF; se o contrato já tem número no PNCP, o arquivo vai para a fila. */
    public function anexarContrato(Request $request, Contrato $contrato, FilaPncp $fila): RedirectResponse
    {
        $request->validate(['arquivo' => ['required', 'file', 'mimes:pdf', 'max:20480']], [], ['arquivo' => 'contrato assinado']);

        $arquivo = $request->file('arquivo');
        $caminho = $arquivo->storeAs("orgaos/{$contrato->orgao_id}/contratos/{$contrato->id}", Str::uuid().'.pdf', 'local');
        if ($contrato->arquivo_caminho) {
            Storage::disk('local')->delete($contrato->arquivo_caminho);
        }
        $contrato->forceFill([
            'arquivo_caminho' => $caminho,
            'arquivo_nome' => mb_substr($arquivo->getClientOriginalName(), 0, 160),
            'arquivo_hash' => hash_file('sha256', $arquivo->getRealPath()),
        ])->save();

        if ($contrato->numero_controle_pncp) {
            return $this->resposta($fila->agendar($contrato, 'arquivo_contrato', 'inserir', $request->user()), 'Contrato assinado anexado e na fila do PNCP.');
        }

        return back()->with('sucesso', 'Contrato assinado anexado. Ele segue ao PNCP logo depois da publicação do contrato.');
    }

    public function baixarContrato(Contrato $contrato)
    {
        abort_unless($contrato->arquivo_caminho && Storage::disk('local')->exists($contrato->arquivo_caminho), 404);
        Auditoria::registrar('download', $contrato, "Download do contrato assinado {$contrato->identificacao()}");

        return Storage::disk('local')->download($contrato->arquivo_caminho, $contrato->arquivo_nome ?: 'contrato.pdf');
    }

    /** Exclusão no PNCP exige justificativa, que fica pública junto do registro. */
    public function excluir(Request $request, EnvioPncp $envio, FilaPncp $fila): RedirectResponse
    {
        $dados = $request->validate(['justificativa' => ['required', 'string', 'min:10', 'max:255']]);
        $alvo = $envio->publicavel;
        abort_unless($alvo && $envio->estado === 'publicado' && in_array($envio->recurso, ['compra', 'contrato', 'termo', 'ata', 'pca'], true), 422);

        return $this->resposta($fila->agendar($alvo, $envio->recurso, 'excluir', $request->user(), $dados['justificativa']), 'Exclusão na fila do PNCP.');
    }

    private function resposta(EnvioPncp $envio, string $ok): RedirectResponse
    {
        return back()->with($envio->estado === 'erro' ? 'erro' : 'sucesso', $envio->estado === 'erro' ? $envio->erro : $ok);
    }

    /** Monta de novo a partir dos dados atuais (depois da correção) e põe na fila. */
    public function reenviar(Request $request, EnvioPncp $envio, FilaPncp $fila): RedirectResponse
    {
        if ($envio->estado !== 'erro') {
            return back()->with('erro', 'Só envios com erro podem ser reenviados.');
        }

        $alvo = $envio->publicavel;
        abort_unless($alvo, 404);
        $novo = $fila->agendar($alvo, $envio->recurso, $envio->operacao, $request->user());

        return back()->with($novo->estado === 'erro' ? 'erro' : 'sucesso', $novo->estado === 'erro' ? $novo->erro : 'Envio refeito com os dados atuais e colocado na fila.');
    }
}
