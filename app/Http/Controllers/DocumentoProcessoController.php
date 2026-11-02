<?php

namespace App\Http\Controllers;

use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\Fluxo\FluxoDocumento;
use App\Licitacao\RegraViolada;
use App\Licitacao\TiposDocumento;
use App\Models\DocumentoProcesso;
use App\Models\ModeloDocumento;
use App\Models\Processo;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Peças da instrução, com o fluxo configurado pelo órgão (dependências, revisão, aprovação e assinatura).
 * Cada tipo tem um perfil responsável: parecer jurídico só pela assessoria jurídica, autorização só
 * pela autoridade competente, as demais por quem instrui o processo.
 */
class DocumentoProcessoController extends Controller
{
    public function __construct(private FluxoDocumento $fluxo, private Assinaturas $assinaturas) {}

    public function edit(Request $request, Processo $processo, string $tipo): View
    {
        $def = $this->definicao($tipo);
        $atual = $processo->documentoAtual($tipo);
        $usuario = $request->user();
        $etapa = $this->fluxo->etapa($processo, $tipo);
        $podeEditar = $this->fluxo->podeElaborar($usuario, $processo, $tipo) && ($atual === null || in_array($atual->situacao, ['rascunho', 'concluido'], true)) && $this->fluxo->dependenciasPendentes($processo, $tipo) === [];

        $modelos = ModeloDocumento::where('tipo', $tipo)->where('ativo', true)->orderBy('titulo')->get()
            ->filter(fn (ModeloDocumento $m) => ($m->aplicacao === 'todos' || $m->aplicacao === ($processo->modalidadeEnum()->ehContratacaoDireta() ? 'direta' : 'licitacao')) && ($m->categoria === null || $m->categoria === $processo->categoria))
            ->values();

        $modelo = $request->integer('modelo') ? $modelos->firstWhere('id', $request->integer('modelo')) : null;
        $valores = $modelo ? $modelo->renderizar($processo) : null;

        return view('processos.documento', [
            'processo' => $processo, 'tipo' => $tipo, 'def' => $def, 'atual' => $atual, 'etapa' => $etapa,
            'estado' => $this->fluxo->estado($processo, $tipo),
            'versoes' => $processo->documentos()->where('tipo', $tipo)->with('autor')->orderByDesc('versao')->get(),
            'podeEditar' => $podeEditar,
            'destino' => $this->fluxo->destino($etapa),
            'modelos' => $modelos, 'modelo' => $modelo, 'valores' => $valores,
            'podeDecidir' => $atual ? $this->fluxo->podeDecidir($usuario, $atual) : false,
            'podeAssinar' => $atual ? $this->fluxo->podeAssinar($usuario, $atual) && ! $atual->assinaturas->contains('user_id', $usuario->id) : false,
            'assinantes' => $atual ? User::whereIn('id', $this->fluxo->assinantes($atual))->get()->keyBy('id') : collect(),
        ]);
    }

    public function salvar(Request $request, Processo $processo, string $tipo): RedirectResponse
    {
        $def = $this->definicao($tipo);
        $usuario = $request->user();
        abort_unless($this->fluxo->podeElaborar($usuario, $processo, $tipo), 403);

        try {
            $this->fluxo->exigirPodeElaborar($usuario, $processo, $tipo);
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Esta peça ainda não está liberada.')->with('motivos', $e->motivos);
        }

        $atual = $processo->documentoAtual($tipo);

        if ($atual?->emTramitacao()) {
            return back()->with('erro', 'A peça está em tramitação e não pode ser editada. Peça a devolução para ajustes.');
        }

        $etapa = $this->fluxo->etapa($processo, $tipo);
        $origem = ($etapa?->origem === 'arquivo' || $request->input('origem') === 'arquivo') ? 'arquivo' : 'formulario';
        $concluir = $request->input('acao') === 'concluir';

        $regras = ['modelo_id' => ['nullable', 'integer']];
        foreach ($def['campos'] as $campo => $d) {
            $regras["conteudo.{$campo}"] = ['nullable', 'string', 'max:20000'];
        }
        if ($origem === 'arquivo') {
            $regras['arquivo'] = [($atual?->origem === 'arquivo' && $atual->arquivo_caminho) ? 'nullable' : 'required', 'file', 'mimes:pdf,docx,odt,xlsx,ods,png,jpg,jpeg', 'max:30720'];
        }

        $validados = $request->validate($regras, ['arquivo.required' => 'Escolha o arquivo da peça.', 'arquivo.mimes' => 'Envie PDF, DOCX, ODT, XLSX, ODS, PNG ou JPG.', 'arquivo.max' => 'O arquivo pode ter no máximo 30 MB.']);
        $conteudo = array_map(fn ($v) => $v === null ? '' : trim($v), $validados['conteudo'] ?? []);

        if ($origem === 'formulario' && $concluir) {
            if ($faltando = TiposDocumento::camposFaltando($tipo, $conteudo)) {
                return back()->withInput()->with('erro', 'Para concluir, preencha os campos obrigatórios.')->with('motivos', array_map(fn ($f) => "Falta: {$f}", $faltando));
            }

            if ($tipo === 'parecer_juridico' && ! preg_match('/^\s*(favor[aá]vel|desfavor[aá]vel)/iu', $conteudo['conclusao'] ?? '')) {
                return back()->withInput()->with('erro', 'A conclusão do parecer precisa começar por "favorável", "favorável com ressalvas" ou "desfavorável".');
            }
        }

        $dados = ['conteudo' => $origem === 'arquivo' ? [] : $conteudo, 'autor_id' => $usuario->id, 'origem' => $origem];

        if ($origem === 'arquivo' && $request->hasFile('arquivo')) {
            $arquivo = $request->file('arquivo');
            $dados += [
                'arquivo_caminho' => $arquivo->store("orgaos/{$processo->orgao_id}/processos/{$processo->id}/{$tipo}", 'local'),
                'arquivo_nome' => Str::limit(preg_replace('/[^\pL\pN._ -]+/u', '_', basename($arquivo->getClientOriginalName())), 120, ''),
                'arquivo_tamanho' => $arquivo->getSize(),
                'arquivo_hash' => hash_file('sha256', $arquivo->getRealPath()),
            ];
        }

        if (! empty($validados['modelo_id']) && $m = ModeloDocumento::where('tipo', $tipo)->find($validados['modelo_id'])) {
            $dados += ['modelo_id' => $m->id, 'modelo_versao' => $m->versao];
        }

        // Rascunho existente é atualizado; documento concluído gera uma nova versão.
        if ($atual && $atual->situacao === 'rascunho') {
            $atual->update($dados);
            $doc = $atual;
        } else {
            $doc = DocumentoProcesso::create($dados + [
                'orgao_id' => $processo->orgao_id, 'processo_id' => $processo->id, 'tipo' => $tipo,
                'versao' => ($atual?->versao ?? 0) + 1, 'situacao' => 'rascunho',
            ]);
        }

        $mensagem = 'salvo como rascunho.';

        if ($concluir) {
            $doc->setRelation('processo', $processo);
            $mensagem = match ($this->fluxo->encaminhar($doc, $usuario)) {
                'concluido' => "concluído (versão {$doc->versao}).",
                'em_revisao' => 'enviado para revisão.',
                'em_aprovacao' => 'enviado para aprovação.',
                'aguardando_assinatura' => 'enviado para assinatura.',
            };
        }

        return redirect()->route('documentos.edit', [$processo, $tipo])->with('sucesso', "{$def['rotulo']} {$mensagem}");
    }

    public function decidir(Request $request, Processo $processo, string $tipo): RedirectResponse
    {
        $this->definicao($tipo);
        $dados = $request->validate(['acao' => ['required', 'in:aprovar,devolver'], 'motivo' => ['nullable', 'string', 'max:2000']]);
        $doc = $processo->documentoAtual($tipo) ?? abort(404);
        $doc->setRelation('processo', $processo);

        try {
            if ($dados['acao'] === 'aprovar') {
                $dest = $this->fluxo->aprovar($doc, $request->user());
                $msg = $dest === 'concluido' ? 'Peça aprovada e concluída.' : 'Peça aprovada e enviada para a próxima etapa.';
            } else {
                $this->fluxo->devolver($doc, $request->user(), (string) ($dados['motivo'] ?? ''));
                $msg = 'Peça devolvida para ajustes.';
            }
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível concluir a ação.')->with('motivos', $e->motivos);
        }

        return redirect()->route('documentos.edit', [$processo, $tipo])->with('sucesso', $msg);
    }

    public function assinar(Request $request, Processo $processo, string $tipo): RedirectResponse
    {
        $this->definicao($tipo);
        $dados = $request->validate(['senha' => ['required', 'string', 'max:255']], ['senha.required' => 'Digite a sua senha para assinar.']);
        $doc = $processo->documentoAtual($tipo) ?? abort(404);
        $doc->setRelation('processo', $processo);

        try {
            $a = $this->assinaturas->assinar($doc, $request->user(), $dados['senha'], $request->ip());
        } catch (RegraViolada $e) {
            return back()->with('erro', 'Não foi possível assinar.')->with('motivos', $e->motivos);
        }

        return redirect()->route('documentos.edit', [$processo, $tipo])->with('sucesso', 'Assinado. Código de validação: '.Assinaturas::formatarCodigo($a->codigo).'.');
    }

    public function arquivo(Processo $processo, DocumentoProcesso $documento): StreamedResponse
    {
        abort_unless($documento->processo_id === $processo->id && $documento->arquivo_caminho && Storage::disk('local')->exists($documento->arquivo_caminho), 404);
        Auditoria::registrar('download', $documento, "Download do arquivo da peça {$documento->tipo} v{$documento->versao} do processo {$processo->identificacao()}");

        return Storage::disk('local')->download($documento->arquivo_caminho, $documento->arquivo_nome ?? 'peca');
    }

    /** Folha de assinaturas para imprimir ou salvar em PDF, com QR Code de validação. */
    public function folha(Processo $processo, string $tipo): View
    {
        $def = $this->definicao($tipo);
        $doc = $processo->documentoAtual($tipo) ?? abort(404);
        $doc->load('assinaturas');

        return view('processos.folha-assinaturas', [
            'processo' => $processo, 'def' => $def, 'doc' => $doc, 'assinaturas' => $this->assinaturas,
            'ordem' => $this->fluxo->assinantes($doc),
        ]);
    }

    private function definicao(string $tipo): array
    {
        abort_unless(array_key_exists($tipo, TiposDocumento::todos()), 404);

        return TiposDocumento::get($tipo);
    }
}
