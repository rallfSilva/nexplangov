<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Nexlicita\Impugnacoes;
use App\Licitacao\RegraViolada;
use App\Models\Impugnacao;
use App\Models\Processo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Impugnações e esclarecimentos (art. 164): painel interno e página pública do edital. */
class ImpugnacaoController extends Controller
{
    public function __construct(private Impugnacoes $servico) {}

    public function index(Processo $processo): View
    {
        return view('licitacao.impugnacoes', ['processo' => $processo, 'impugnacoes' => $processo->impugnacoes()->get()]);
    }

    public function store(Request $request, Processo $processo): RedirectResponse
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Impugnacao::TIPOS))], 'autor_nome' => ['required', 'string', 'max:160'],
            'autor_email' => ['nullable', 'email', 'max:160'], 'texto' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        return $this->tentar(fn () => $this->servico->registrar($processo, $d['tipo'], $d['autor_nome'], $d['autor_email'] ?? null, $d['texto']), 'Pedido registrado.');
    }

    public function responder(Request $request, Processo $processo, Impugnacao $impugnacao): RedirectResponse
    {
        abort_unless($impugnacao->processo_id === $processo->id, 404);
        $d = $request->validate(['resultado' => ['required', Rule::in([...array_keys(Impugnacao::RESULTADOS), 'nao_conhecida'])], 'resposta' => ['required', 'string', 'max:10000']]);

        return $this->tentar(fn () => $this->servico->responder($impugnacao, $request->user(), $d['resultado'], $d['resposta']), 'Resposta registrada e divulgada na página pública do edital.');
    }

    /** Página pública do edital: dados básicos, respostas divulgadas e formulário de pedido. */
    public function edital(Processo $processo): View
    {
        abort_unless(in_array($processo->situacao, ['publicado', 'em_julgamento', 'homologado', 'contratado'], true) && ! Modalidade::from($processo->modalidade)->ehContratacaoDireta(), 404);

        return view('licitacao.edital-publico', [
            'processo' => $processo->load('orgao'),
            'respostas' => $processo->impugnacoes()->whereIn('situacao', ['respondida', 'nao_conhecida'])->get(),
            'aceita' => in_array($processo->situacao, ['publicado', 'em_julgamento'], true),
            'recursos' => $processo->recursos()->with(['fornecedor', 'item', 'contrarrazoes.fornecedor'])->orderBy('id')->get(),
        ]);
    }

    /** Ata da sessão pública: documento público do processo (art. 165, § 5º, vista dos autos). */
    public function ataPublica(Processo $processo)
    {
        abort_unless($processo->ata_sessao_caminho && ! Modalidade::from($processo->modalidade)->ehContratacaoDireta()
            && Storage::disk('local')->exists($processo->ata_sessao_caminho), 404);

        return Storage::disk('local')->download($processo->ata_sessao_caminho, 'ata-sessao-'.str_replace('/', '-', $processo->identificacao()).'.pdf');
    }

    public function pedidoPublico(Request $request, Processo $processo): RedirectResponse
    {
        abort_unless(in_array($processo->situacao, ['publicado', 'em_julgamento'], true), 404);

        // Campo-isca: robô preenche, pessoa não vê.
        if ($request->filled('site')) {
            return back()->with('sucesso', 'Pedido recebido.');
        }

        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Impugnacao::TIPOS))], 'autor_nome' => ['required', 'string', 'max:160'],
            'autor_email' => ['required', 'email', 'max:160'], 'texto' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        try {
            $i = $this->servico->registrar($processo, $d['tipo'], $d['autor_nome'], $d['autor_email'], $d['texto'], 'publico');
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', implode(' ', $e->motivos));
        }

        return back()->with('sucesso', "Pedido recebido. Guarde o protocolo {$i->protocolo}.");
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
