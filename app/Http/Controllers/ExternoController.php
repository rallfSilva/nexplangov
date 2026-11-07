<?php

namespace App\Http\Controllers;

use App\Licitacao\Externos\PedidosExternos;
use App\Licitacao\Regras\PedidoExterno;
use App\Licitacao\RegraViolada;
use App\Licitacao\TiposDocumento;
use App\Models\JuntadaProcesso;
use App\Models\SolicitacaoExterna;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Página pública do link enviado por e-mail: a pessoa de fora dá ciência, assina ou envia documentos. */
class ExternoController extends Controller
{
    public function __construct(private PedidosExternos $pedidos) {}

    public function mostrar(string $token): View
    {
        $s = SolicitacaoExterna::porToken($token);
        abort_if($s === null, 404);
        $s->load(['processo.orgao', 'documento.assinaturas']);

        return view('externo.pedido', [
            's' => $s,
            'bloqueio' => PedidoExterno::motivosResposta($s->cancelada_em !== null, $s->respondida_em !== null, $s->prazo_em, now()),
            'def' => $s->documento ? TiposDocumento::get($s->documento->tipo) : null,
        ]);
    }

    public function responder(Request $request, string $token): RedirectResponse
    {
        $s = SolicitacaoExterna::porToken($token);
        abort_if($s === null, 404);

        $regras = match ($s->tipo) {
            'assinatura' => ['nome' => ['required', 'string', 'max:160'], 'ciente' => ['accepted']],
            'documentos' => ['arquivos' => ['required', 'array', 'min:1', 'max:5'], 'arquivos.*' => ['file', 'mimes:'.JuntadaProcesso::MIMES, 'max:30720'], 'observacao' => ['nullable', 'string', 'max:1000']],
            default => ['ciente' => ['accepted']],
        };
        $d = $request->validate($regras, ['arquivos.*.mimes' => 'Envie PDF, DOCX, ODT, XLSX, ODS, PNG ou JPG.', 'arquivos.*.max' => 'Cada arquivo pode ter no máximo 30 MB.', 'ciente.accepted' => 'Marque a declaração para continuar.']);

        try {
            $msg = match ($s->tipo) {
                'assinatura' => tap('Assinatura registrada. Obrigado.', fn () => $this->pedidos->assinar($s, $d['nome'], $request->ip())),
                'documentos' => $this->pedidos->receberDocumentos($s, $request->file('arquivos'), $d['observacao'] ?? null, $request->ip()).' arquivo(s) recebido(s) e juntado(s) ao processo. Obrigado.',
                default => tap('Ciência registrada. Obrigado.', fn () => $this->pedidos->darCiencia($s, $request->ip())),
            };
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível registrar a resposta.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', $msg);
    }
}
