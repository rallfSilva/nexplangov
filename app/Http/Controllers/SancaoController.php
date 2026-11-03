<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\Responsabilizacao;
use App\Licitacao\Regras\Sancoes;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Models\Fornecedor;
use App\Models\ProcessoSancionador;
use App\Models\SancaoFornecedor;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Processo de responsabilização de licitante ou contratado (arts. 155 a 163). */
class SancaoController extends Controller
{
    public function __construct(private Responsabilizacao $servico) {}

    public function index(Request $request): View
    {
        $situacao = $request->query('situacao');
        $processos = ProcessoSancionador::with('fornecedor')
            ->when($situacao && array_key_exists($situacao, ProcessoSancionador::SITUACOES), fn ($q) => $q->where('situacao', $situacao))
            ->orderByDesc('id')->paginate(30)->withQueryString();
        $ceisPendentes = ProcessoSancionador::whereIn('situacao', ['definitivo', 'reabilitado'])->whereNull('ceis_informado_em')->orderBy('prazo_ceis')->get();

        return view('sancoes.index', ['processos' => $processos, 'ceisPendentes' => $ceisPendentes, 'situacao' => $situacao]);
    }

    public function create(Request $request): View
    {
        return view('sancoes.create', [
            'fornecedores' => Fornecedor::orderBy('razao_social')->get(['id', 'razao_social', 'documento']),
            'contratos' => Contrato::orderByDesc('ano')->orderByDesc('numero')->get(['id', 'numero', 'ano', 'fornecedor_id', 'objeto']),
            'servidores' => User::where('ativo', true)->whereNotNull('orgao_id')->orderBy('name')->get(['id', 'name']),
            'fornecedorId' => $request->integer('fornecedor') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'fornecedor_id' => ['required', 'integer'], 'contrato_id' => ['nullable', 'integer'],
            'infracoes' => ['required', 'array', 'min:1'], 'infracoes.*' => [Rule::in(array_keys(Sancoes::INFRACOES))],
            'fatos' => ['required', 'string', 'max:10000'], 'sancao_proposta' => ['required', Rule::in(array_keys(SancaoFornecedor::TIPOS))],
            'justificativa_agravamento' => ['nullable', 'string', 'max:5000'], 'comissao' => ['nullable', 'array'], 'comissao.*' => ['integer'], 'comissao_estaveis' => ['nullable', 'boolean'],
        ]);
        $fornecedor = Fornecedor::findOrFail($d['fornecedor_id']);
        if ($d['contrato_id'] ?? null) {
            $contrato = Contrato::findOrFail($d['contrato_id']);
            abort_unless($contrato->fornecedor_id === $fornecedor->id, 422);
            $d['processo_id'] = $contrato->processo_id;
        }

        try {
            $ps = $this->servico->instaurar($fornecedor, $d, $request->user());
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível instaurar.')->with('motivos', $e->motivos);
        }

        return redirect()->route('sancoes.show', $ps)->with('sucesso', "Processo {$ps->numero} instaurado. Próximo passo: intimar o fornecedor.");
    }

    public function show(ProcessoSancionador $sancionador): View
    {
        $sancionador->load(['fornecedor', 'contrato', 'processo', 'sancao', 'decisor']);

        return view('sancoes.show', [
            'ps' => $sancionador,
            'comissao' => User::whereIn('id', $sancionador->comissao ?? [])->orderBy('name')->pluck('name'),
        ]);
    }

    public function intimar(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['intimado_em' => ['required', 'date'], 'meio' => ['required', 'string', 'max:200']]);

        return $this->tentar(fn () => $this->servico->intimar($sancionador, $d['intimado_em'], $d['meio']), 'Intimação registrada. A notificação em PDF está pronta para envio.');
    }

    public function defesa(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:10000'], 'recebida_em' => ['required', 'date'], 'provas' => ['nullable', 'boolean'], 'arquivo' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:20480']]);

        return $this->tentar(fn () => $this->servico->registrarDefesa($sancionador, $d['texto'], (bool) ($d['provas'] ?? false), $d['recebida_em'], $request->file('arquivo')), 'Defesa registrada.');
    }

    public function instrucao(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['relatorio' => ['required', 'string', 'max:10000'], 'intimado_em' => ['required', 'date']]);

        return $this->tentar(fn () => $this->servico->encerrarInstrucao($sancionador, $d['relatorio'], $d['intimado_em']), 'Instrução encerrada. Prazo de alegações finais aberto.');
    }

    public function alegacoes(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:10000'], 'recebidas_em' => ['required', 'date']]);

        return $this->tentar(fn () => $this->servico->registrarAlegacoes($sancionador, $d['texto'], $d['recebidas_em']), 'Alegações finais registradas.');
    }

    public function parecer(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:20000']]);

        return $this->tentar(fn () => $this->servico->registrarParecer($sancionador, $d['texto'], $request->user()), 'Parecer jurídico registrado.');
    }

    public function decidir(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $request->merge(['base_calculo' => decimal_br($request->input('base_calculo')), 'multa_valor' => decimal_br($request->input('multa_valor'))]);
        $d = $request->validate([
            'resultado' => ['required', 'in:aplicada,arquivado'], 'sancao_tipo' => ['nullable', Rule::in(array_keys(SancaoFornecedor::TIPOS))],
            'base_calculo' => ['nullable', 'numeric', 'gte:0'], 'multa_valor' => ['nullable', 'numeric', 'gte:0'], 'duracao_meses' => ['nullable', 'integer', 'min:1', 'max:72'],
            'dosimetria' => ['nullable', 'string', 'max:10000'], 'decisao' => ['required', 'string', 'max:20000'],
        ]);

        return $this->tentar(fn () => $this->servico->decidir($sancionador, $d, $request->user()), $d['resultado'] === 'arquivado' ? 'Processo arquivado.' : 'Decisão registrada. Intime o fornecedor: o prazo de recurso é de 15 dias úteis.');
    }

    public function recurso(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:10000'], 'recebido_em' => ['required', 'date']]);

        return $this->tentar(fn () => $this->servico->recorrer($sancionador, $d['texto'], $d['recebido_em']), 'Recurso registrado. A sanção fica suspensa até a decisão (art. 168).');
    }

    public function reconsiderar(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['acao' => ['required', 'in:reconsiderar,manter'], 'texto' => ['required', 'string', 'max:10000']]);

        return $this->tentar(fn () => $this->servico->reconsiderar($sancionador, $d['acao'] === 'reconsiderar', $d['texto'], $request->user()), $d['acao'] === 'reconsiderar' ? 'Decisão reconsiderada. Processo arquivado.' : 'Decisão mantida. O recurso segue para a autoridade superior.');
    }

    public function decidirRecurso(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $request->merge(['base_calculo' => decimal_br($request->input('base_calculo')), 'multa_valor' => decimal_br($request->input('multa_valor'))]);
        $d = $request->validate([
            'resultado' => ['required', Rule::in(array_keys(ProcessoSancionador::RESULTADOS_RECURSO))], 'decisao' => ['required', 'string', 'max:20000'],
            'sancao_tipo' => ['nullable', Rule::in(array_keys(SancaoFornecedor::TIPOS))], 'base_calculo' => ['nullable', 'numeric', 'gte:0'],
            'multa_valor' => ['nullable', 'numeric', 'gte:0'], 'duracao_meses' => ['nullable', 'integer', 'min:1', 'max:72'], 'dosimetria' => ['nullable', 'string', 'max:10000'],
        ]);

        return $this->tentar(fn () => $this->servico->decidirRecurso($sancionador, $d, $request->user()), 'Recurso decidido.');
    }

    public function definitiva(ProcessoSancionador $sancionador): RedirectResponse
    {
        return $this->tentar(fn () => $this->servico->tornarDefinitiva($sancionador), 'Sanção definitiva e registrada no cadastro do fornecedor. Informe ao CEIS/CNEP em até 15 dias úteis (art. 161).');
    }

    public function ceis(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['informado_em' => ['required', 'date'], 'protocolo' => ['required', 'string', 'max:80']]);

        return $this->tentar(fn () => $this->servico->registrarCeis($sancionador, $d['informado_em'], $d['protocolo']), 'Comunicação ao cadastro nacional registrada.');
    }

    public function reabilitar(Request $request, ProcessoSancionador $sancionador): RedirectResponse
    {
        $d = $request->validate(['fundamento' => ['required', 'string', 'max:10000'], 'requisitos' => ['nullable', 'array'], 'requisitos.*' => ['in:reparacao,multa_paga,condicoes,integridade,parecer']]);
        $marcados = $d['requisitos'] ?? [];
        $requisitos = collect(['reparacao', 'multa_paga', 'condicoes', 'integridade', 'parecer'])->mapWithKeys(fn ($r) => [$r => in_array($r, $marcados, true)])->all();

        return $this->tentar(fn () => $this->servico->reabilitar($sancionador, $requisitos, $d['fundamento']), 'Fornecedor reabilitado. Atualize o CEIS/CNEP (art. 161).');
    }

    public function arquivo(ProcessoSancionador $sancionador, string $tipo): StreamedResponse
    {
        $caminho = $tipo === 'intimacao' ? $sancionador->intimacao_caminho : $sancionador->defesa_caminho;
        abort_unless($caminho && Storage::disk('local')->exists($caminho), 404);
        Auditoria::registrar('download', $sancionador, "Baixou o arquivo ({$tipo}) do processo sancionador {$sancionador->numero}");

        return Storage::disk('local')->download($caminho, "{$tipo}-{$sancionador->numero}.".pathinfo($caminho, PATHINFO_EXTENSION));
    }

    /**
     * Planilha para lançar no CEIS/CNEP (art. 161), com os campos que o cadastro pede. O documento completo
     * do fornecedor sai porque o registro é obrigação legal (LGPD, art. 7º, II); a geração fica na auditoria.
     */
    public function exportarCeis(Request $request): StreamedResponse
    {
        $lista = ProcessoSancionador::with(['fornecedor', 'orgao', 'sancao'])->whereIn('situacao', ['definitivo', 'reabilitado'])
            ->when(! $request->boolean('todos'), fn ($q) => $q->whereNull('ceis_informado_em'))->orderBy('definitivo_em')->get();
        Auditoria::registrar('exportacao', null, 'Gerou a planilha de sanções para o CEIS/CNEP ('.$lista->count().' registro(s))');

        return response()->streamDownload(function () use ($lista) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Cadastro', 'Tipo de pessoa', 'CPF/CNPJ do sancionado', 'Nome ou razão social', 'Tipo de sanção', 'Fundamentação legal', 'Data de início', 'Data final',
                'Abrangência', 'Valor da multa', 'Número do processo', 'Órgão sancionador', 'CNPJ do órgão sancionador', 'UF', 'Esfera', 'Data da decisão', 'Data de reabilitação'], ';');
            foreach ($lista as $ps) {
                $f = $ps->fornecedor;
                fputcsv($out, [
                    $ps->cadastroNacional(), $f->tipo_pessoa === 'PF' ? 'Física' : 'Jurídica', $f->documentoCompleto(), $f->razao_social, SancaoFornecedor::TIPOS[$ps->sancao_tipo] ?? $ps->sancao_tipo,
                    'Lei nº 14.133/2021, art. 155, '.implode(', ', $ps->infracoes).', e art. 156', $ps->sancao?->inicio?->format('d/m/Y'), $ps->sancao?->fim?->format('d/m/Y'),
                    match ($ps->sancao?->abrangencia) {
                        'ente' => 'No âmbito do ente federativo (art. 156, § 4º)', 'nacional' => 'Todos os entes federativos (art. 156, § 5º)', default => ''
                    },
                    $ps->multa_valor !== null ? number_format((float) $ps->multa_valor, 2, ',', '.') : '', $ps->numero, $ps->orgao->nome, $ps->orgao->cnpj, $ps->orgao->uf, $ps->orgao->esfera,
                    $ps->decidido_em?->format('d/m/Y'), $ps->reabilitado_em?->format('d/m/Y'),
                ], ';');
            }
            fclose($out);
        }, 'sancoes-ceis-cnep-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
