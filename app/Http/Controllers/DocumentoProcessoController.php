<?php

namespace App\Http\Controllers;

use App\Licitacao\TiposDocumento;
use App\Models\DocumentoProcesso;
use App\Models\Processo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Peças da instrução. Cada tipo tem um perfil responsável: parecer jurídico só pela assessoria
 * jurídica, autorização só pela autoridade competente, as demais por quem instrui o processo.
 */
class DocumentoProcessoController extends Controller
{
    private const PERMISSAO = [
        'parecer_juridico' => 'processos.parecer',
        'autorizacao' => 'processos.autorizar',
    ];

    public function edit(Request $request, Processo $processo, string $tipo): View
    {
        $def = $this->definicao($tipo);
        $atual = $processo->documentoAtual($tipo);
        $podeEditar = $this->podeEditar($request, $processo, $tipo);

        return view('processos.documento', [
            'processo' => $processo,
            'tipo' => $tipo,
            'def' => $def,
            'atual' => $atual,
            'versoes' => $processo->documentos()->where('tipo', $tipo)->with('autor')->orderByDesc('versao')->get(),
            'podeEditar' => $podeEditar,
        ]);
    }

    public function salvar(Request $request, Processo $processo, string $tipo): RedirectResponse
    {
        $def = $this->definicao($tipo);
        abort_unless($this->podeEditar($request, $processo, $tipo), 403);

        $regras = [];
        foreach ($def['campos'] as $campo => $d) {
            $regras["conteudo.{$campo}"] = ['nullable', 'string', 'max:20000'];
        }
        $conteudo = $request->validate($regras)['conteudo'] ?? [];
        $conteudo = array_map(fn ($v) => $v === null ? '' : trim($v), $conteudo);

        $concluir = $request->input('acao') === 'concluir';

        if ($concluir && ($faltando = TiposDocumento::camposFaltando($tipo, $conteudo))) {
            return back()->withInput()->with('erro', 'Para concluir, preencha os campos obrigatórios.')->with('motivos', array_map(fn ($f) => "Falta: {$f}", $faltando));
        }

        if ($tipo === 'parecer_juridico' && $concluir && ! preg_match('/^\s*(favor[aá]vel|desfavor[aá]vel)/iu', $conteudo['conclusao'] ?? '')) {
            return back()->withInput()->with('erro', 'A conclusão do parecer precisa começar por "favorável", "favorável com ressalvas" ou "desfavorável".');
        }

        $atual = $processo->documentoAtual($tipo);

        // Rascunho existente é atualizado; documento concluído gera uma nova versão.
        if ($atual && $atual->situacao === 'rascunho') {
            $atual->update(['conteudo' => $conteudo, 'autor_id' => $request->user()->id]);
            $doc = $atual;
        } else {
            $doc = DocumentoProcesso::create([
                'orgao_id' => $processo->orgao_id,
                'processo_id' => $processo->id,
                'tipo' => $tipo,
                'versao' => ($atual?->versao ?? 0) + 1,
                'conteudo' => $conteudo,
                'situacao' => 'rascunho',
                'autor_id' => $request->user()->id,
            ]);
        }

        if ($concluir) {
            $doc->concluir();
        }

        return redirect()->route('processos.show', [$processo, 'aba' => 'instrucao'])
            ->with('sucesso', "{$def['rotulo']} ".($concluir ? "concluído (versão {$doc->versao})." : 'salvo como rascunho.'));
    }

    private function definicao(string $tipo): array
    {
        abort_unless(array_key_exists($tipo, TiposDocumento::todos()), 404);

        return TiposDocumento::get($tipo);
    }

    private function podeEditar(Request $request, Processo $processo, string $tipo): bool
    {
        $permissao = self::PERMISSAO[$tipo] ?? 'processos.gerenciar';

        if (! $request->user()->can($permissao)) {
            return false;
        }

        return match ($tipo) {
            'parecer_juridico' => $processo->situacao === 'analise_juridica',
            'autorizacao' => $processo->situacao === 'aguardando_autorizacao',
            default => $processo->editavel(),
        };
    }
}
