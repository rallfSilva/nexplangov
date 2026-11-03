<?php

namespace App\Http\Controllers;

use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Nexlicita\Recursos;
use App\Licitacao\Nexlicita\Sessao;
use App\Licitacao\Nexlicita\SessaoPublica;
use App\Licitacao\Regras\Julgamento;
use App\Licitacao\RegraViolada;
use App\Models\Fornecedor;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\Proposta;
use App\Models\Recurso;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** NexLicita: sessão de julgamento do processo (propostas, lances, habilitação e adjudicação). */
class LicitacaoController extends Controller
{
    public function __construct(private Sessao $sessao, private SessaoPublica $sessaoPublica, private Recursos $recursos) {}

    public function show(Request $request, Processo $processo): View
    {
        abort_if(Modalidade::from($processo->modalidade)->ehContratacaoDireta(), 404);
        $processo->load(['itens.propostas.fornecedor', 'itens.propostas.lances', 'recursos.fornecedor', 'recursos.item', 'recursos.contrarrazoes.fornecedor']);
        $foco = $request->integer('item') ?: null;

        return view('licitacao.sessao', [
            'processo' => $processo,
            'foco' => $processo->itens->firstWhere('numero', $foco) ? $foco : null,
            'licitantes' => $processo->itens->flatMap->propostas->pluck('fornecedor')->unique('id')->sortBy('razao_social')->values(),
            'classificacoes' => $processo->itens->mapWithKeys(fn (ItemProcesso $i) => [$i->id => $this->sessao->classificacao($i)])->all(),
            'fornecedores' => Fornecedor::where('ativo', true)->orderBy('razao_social')->get(['id', 'razao_social', 'porte']),
            'desempates' => Julgamento::DESEMPATE,
            'criterio' => $this->sessao->criterio($processo),
            'disputaAberta' => in_array($processo->modo_disputa, [1, 3], true),
        ]);
    }

    public function proposta(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        $request->merge(['valor' => decimal_br($request->input('valor'))]);
        $d = $request->validate([
            'fornecedor_id' => ['required', 'integer', Rule::exists('fornecedores', 'id')->where('orgao_id', $processo->orgao_id)],
            'valor' => ['required', 'numeric', 'gt:0'],
            'marca_modelo' => ['nullable', 'string', 'max:160'],
        ]);

        return $this->executar(fn () => $this->sessao->registrarProposta($processo, $item, Fornecedor::findOrFail($d['fornecedor_id']), (float) $d['valor'], $d['marca_modelo'] ?? null, $request->user()), 'Proposta registrada.');
    }

    public function lance(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $request->merge(['valor' => decimal_br($request->input('valor')), 'intervalo' => decimal_br($request->input('intervalo') ?: '0')]);
        $d = $request->validate(['valor' => ['required', 'numeric', 'gt:0'], 'intervalo' => ['nullable', 'numeric', 'min:0']]);

        return $this->executar(fn () => $this->sessao->registrarLance($proposta, (float) $d['valor'], (float) ($d['intervalo'] ?? 0), $request->user()), 'Lance registrado.');
    }

    public function desclassificar(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->desclassificar($proposta, $d['motivo']), 'Proposta desclassificada.');
    }

    public function habilitar(Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);

        return $this->executar(fn () => $this->sessao->habilitar($proposta), 'Proposta habilitada.');
    }

    public function inabilitar(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->inabilitar($proposta, $d['motivo']), 'Proposta inabilitada. A próxima colocada passa a ser a primeira.');
    }

    public function desempate(Request $request, Processo $processo, Proposta $proposta): RedirectResponse
    {
        abort_unless($proposta->processo_id === $processo->id, 404);
        $d = $request->validate(['criterio' => ['required', Rule::in(array_keys(Julgamento::DESEMPATE))], 'justificativa' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->registrarDesempate($proposta, $d['criterio'], $d['justificativa']), 'Critério de desempate registrado.');
    }

    public function adjudicar(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        abort_unless($item->processo_id === $processo->id, 404);
        $d = $request->validate(['proposta_id' => ['required', 'integer'], 'empate_ficto' => ['nullable', 'string', 'max:500']]);
        $proposta = $item->propostas()->findOrFail($d['proposta_id']);

        return $this->executar(fn () => $this->sessao->adjudicar($item, $proposta, $request->user(), $d['empate_ficto'] ?? null), "Item {$item->numero} adjudicado.");
    }

    public function revogarAdjudicacao(Request $request, Processo $processo, ItemProcesso $item): RedirectResponse
    {
        abort_unless($item->processo_id === $processo->id, 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessao->revogarAdjudicacao($item, $d['motivo']), 'Adjudicação revogada.');
    }

    // Sessão pública: gravação, encerramento com ata e reabertura (art. 17, §§ 2º e 5º)

    public function gravacao(Request $request, Processo $processo): RedirectResponse
    {
        $request->validate([
            'gravacao_link' => ['nullable', 'url:https', 'max:500'],
            'gravacao_arquivo' => ['nullable', 'file', 'mimes:mp4,webm,mkv,mov,mp3,m4a,ogg,wav', 'max:2097152'],
        ], [], ['gravacao_link' => 'endereço da gravação', 'gravacao_arquivo' => 'arquivo da gravação']);

        return $this->executar(fn () => $this->sessaoPublica->registrarGravacao($processo, $request->input('gravacao_link'), $request->file('gravacao_arquivo')), 'Gravação registrada.');
    }

    public function encerrar(Request $request, Processo $processo): RedirectResponse
    {
        return $this->executar(fn () => $this->sessaoPublica->encerrar($processo, $request->user()), 'Sessão encerrada e ata lavrada. O prazo das razões de recurso começou a correr.');
    }

    public function reabrir(Request $request, Processo $processo): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return $this->executar(fn () => $this->sessaoPublica->reabrir($processo, $d['motivo']), 'Sessão reaberta.');
    }

    public function ata(Processo $processo): StreamedResponse
    {
        abort_unless($processo->ata_sessao_caminho && Storage::disk('local')->exists($processo->ata_sessao_caminho), 404);
        Auditoria::registrar('download', $processo, "Download da ata da sessão do processo {$processo->identificacao()}");

        return Storage::disk('local')->download($processo->ata_sessao_caminho, 'ata-sessao-'.str_replace('/', '-', $processo->identificacao()).'.pdf');
    }

    public function baixarGravacao(Processo $processo): StreamedResponse
    {
        abort_unless($processo->gravacao_caminho && Storage::disk('local')->exists($processo->gravacao_caminho), 404);
        Auditoria::registrar('download', $processo, "Download da gravação da sessão do processo {$processo->identificacao()}");

        return Storage::disk('local')->download($processo->gravacao_caminho, $processo->gravacao_nome ?: 'gravacao');
    }

    // Recursos (arts. 165 a 168)

    public function intencao(Request $request, Processo $processo): RedirectResponse
    {
        $d = $request->validate([
            'fornecedor_id' => ['required', 'integer', Rule::exists('fornecedores', 'id')->where('orgao_id', $processo->orgao_id)],
            'tipo' => ['required', Rule::in(array_keys(Recurso::TIPOS))],
            'item_id' => ['nullable', 'integer'],
            'motivo' => ['required', 'string', 'max:2000'],
        ]);
        $item = ($d['item_id'] ?? null) ? $processo->itens()->findOrFail($d['item_id']) : null;

        return $this->executar(fn () => $this->recursos->registrarIntencao($processo, Fornecedor::findOrFail($d['fornecedor_id']), $d['tipo'], $d['motivo'], $item, $request->user()), 'Intenção de recurso registrada.');
    }

    public function razoes(Request $request, Processo $processo, Recurso $recurso): RedirectResponse
    {
        abort_unless($recurso->processo_id === $processo->id, 404);
        $d = $request->validate(['razoes' => ['required', 'string', 'max:20000']]);

        return $this->executar(fn () => $this->recursos->registrarRazoes($recurso, $d['razoes']), 'Razões registradas. O prazo das contrarrazões começou.');
    }

    public function contrarrazoes(Request $request, Processo $processo, Recurso $recurso): RedirectResponse
    {
        abort_unless($recurso->processo_id === $processo->id, 404);
        $d = $request->validate([
            'fornecedor_id' => ['required', 'integer', Rule::exists('fornecedores', 'id')->where('orgao_id', $processo->orgao_id)],
            'texto' => ['required', 'string', 'max:20000'],
        ]);

        return $this->executar(fn () => $this->recursos->registrarContrarrazoes($recurso, Fornecedor::findOrFail($d['fornecedor_id']), $d['texto'], $request->user()), 'Contrarrazões registradas.');
    }

    public function reconsiderar(Request $request, Processo $processo, Recurso $recurso): RedirectResponse
    {
        abort_unless($recurso->processo_id === $processo->id, 404);
        $d = $request->validate(['decisao' => ['required', 'in:reconsiderar,manter'], 'fundamento' => ['required', 'string', 'max:20000']]);

        return $this->executar(fn () => $this->recursos->reconsiderar($recurso, $d['decisao'] === 'reconsiderar', $d['fundamento'], $request->user()),
            $d['decisao'] === 'reconsiderar' ? 'Ato reconsiderado. Refaça na sessão o que a decisão mandar.' : 'Ato mantido. O recurso foi à autoridade superior.');
    }

    public function decidirRecurso(Request $request, Processo $processo, Recurso $recurso): RedirectResponse
    {
        abort_unless($recurso->processo_id === $processo->id, 404);
        $d = $request->validate(['resultado' => ['required', Rule::in(array_keys(Recurso::RESULTADOS))], 'fundamento' => ['required', 'string', 'max:20000']]);

        return $this->executar(fn () => $this->recursos->decidir($recurso, $d['resultado'], $d['fundamento'], $request->user()), 'Recurso decidido.');
    }

    private function executar(callable $acao, string $sucesso): RedirectResponse
    {
        try {
            $acao();
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível concluir.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', $sucesso);
    }
}
