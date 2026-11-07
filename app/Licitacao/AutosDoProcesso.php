<?php

namespace App\Licitacao;

use App\Licitacao\Regras\AutosProcesso;
use App\Models\DocumentoProcesso;
use App\Models\JuntadaProcesso;
use App\Models\Processo;
use App\Models\User;
use App\Support\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Monta os autos do processo (peças concluídas, ata da sessão, contratos e documentos juntados), numerados em
 * ordem cronológica, e gera o PDF único paginado e pesquisável (texto real, não imagem).
 */
final class AutosDoProcesso
{
    /** Imagens acima disto entram só pelo termo de juntada, para o PDF não estourar a memória. */
    private const IMAGEM_MAXIMA = 6 * 1024 * 1024;

    /** @return array{itens: list<array<string, mixed>>, arvore: array<string, list<array<string, mixed>>>} */
    public function montar(Processo $p, bool $comDesentranhados = true): array
    {
        $docs = [];

        $pecas = $p->documentos()->where('situacao', 'concluido')->with('assinaturas')->get()
            ->groupBy('tipo')->map(fn ($v) => $v->sortByDesc('versao')->first());
        foreach ($pecas as $d) {
            $def = TiposDocumento::get($d->tipo);
            $docs[] = ['data' => $d->concluido_em ?? $d->updated_at, 'fase' => AutosProcesso::faseDaPeca($d->tipo), 'titulo' => $def['rotulo'] ?? $d->tipo,
                'tipo' => 'peca', 'base' => $def['base'] ?? null, 'ref' => $d, 'autor' => $d->autor?->name, 'detalhe' => "versão {$d->versao} · {$d->assinaturas->count()} assinatura(s)"];
        }

        if ($p->ata_sessao_caminho && $p->sessao_encerrada_em) {
            $docs[] = ['data' => $p->sessao_encerrada_em, 'fase' => 'selecao', 'titulo' => 'Ata da sessão pública', 'tipo' => 'ata', 'base' => 'art. 17', 'ref' => null, 'autor' => null, 'detalhe' => 'PDF da sessão'];
        }

        foreach ($p->contratos()->get() as $c) {
            $docs[] = ['data' => $c->assinatura ?? $c->created_at, 'fase' => 'contratacao', 'titulo' => "Contrato {$c->identificacao()}", 'tipo' => 'contrato', 'base' => 'art. 89', 'ref' => $c, 'autor' => null, 'detalhe' => $c->fornecedor?->razao_social];
        }

        foreach ($p->juntadas()->with(['autor', 'desentranhador'])->get() as $j) {
            if (! $comDesentranhados && $j->desentranhado_em) {
                continue;
            }
            $docs[] = ['data' => $j->juntado_em, 'fase' => 'juntada', 'titulo' => $j->titulo, 'tipo' => 'juntada', 'base' => JuntadaProcesso::TIPOS[$j->tipo] ?? $j->tipo,
                'ref' => $j, 'autor' => $j->quemJuntou(), 'detalhe' => $j->arquivo_nome.($j->paginas ? " · {$j->paginas} pág." : '')];
        }

        return AutosProcesso::montar($docs);
    }

    public function juntar(Processo $p, User|string $autor, UploadedFile $arquivo, string $tipo, string $titulo, ?string $descricao = null, ?int $solicitacaoId = null): JuntadaProcesso
    {
        $conteudo = (string) file_get_contents($arquivo->getRealPath());
        $externo = is_string($autor);

        return JuntadaProcesso::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'tipo' => $tipo, 'titulo' => Str::limit(trim($titulo), 200, ''), 'descricao' => $descricao ? trim($descricao) : null,
            'arquivo_caminho' => $arquivo->store("orgaos/{$p->orgao_id}/processos/{$p->id}/juntadas", 'local'),
            'arquivo_nome' => Str::limit(preg_replace('/[^\pL\pN._ -]+/u', '_', basename($arquivo->getClientOriginalName())), 120, ''),
            'arquivo_tamanho' => $arquivo->getSize(), 'arquivo_hash' => hash('sha256', $conteudo), 'mime' => $arquivo->getMimeType(),
            'paginas' => AutosProcesso::paginasPdf($conteudo), 'origem' => $externo ? 'externo' : 'interno',
            'autor_id' => $externo ? null : $autor->id, 'autor_externo' => $externo ? Str::limit($autor, 160, '') : null,
            'solicitacao_externa_id' => $solicitacaoId, 'juntado_em' => now(),
        ]);
    }

    public function desentranhar(JuntadaProcesso $j, User $u, ?string $motivo): void
    {
        if ($m = AutosProcesso::motivosDesentranhamento($j->desentranhado_em !== null, $motivo)) {
            throw new RegraViolada($m);
        }

        $j->forceFill(['desentranhado_em' => now(), 'desentranhado_por' => $u->id, 'motivo_desentranhamento' => trim((string) $motivo)])->save();
    }

    /** PDF único dos autos, com capa, índice e cada documento na ordem, paginado como "fl. N de T". */
    public function pdf(Processo $p): string
    {
        $autos = $this->montar($p, false);
        $itens = array_map(fn ($d) => $d + ['imagem' => $this->imagem($d)], $autos['itens']);

        return Pdf::daView('processos.autos-pdf', ['p' => $p->loadMissing(['orgao', 'unidade', 'unidadeAtual']), 'itens' => $itens], "Processo {$p->identificacao()}");
    }

    /** Imagem juntada (ou peça enviada como imagem) vai dentro do PDF como data URI. */
    private function imagem(array $d): ?string
    {
        $ref = $d['ref'];
        [$caminho, $nome] = match (true) {
            $ref instanceof JuntadaProcesso => [$ref->arquivo_caminho, $ref->arquivo_nome],
            $ref instanceof DocumentoProcesso && $ref->origem === 'arquivo' => [$ref->arquivo_caminho, (string) $ref->arquivo_nome],
            default => [null, ''],
        };

        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (! $caminho || ! in_array($ext, ['png', 'jpg', 'jpeg'], true) || ! Storage::disk('local')->exists($caminho) || Storage::disk('local')->size($caminho) > self::IMAGEM_MAXIMA) {
            return null;
        }

        return 'data:image/'.($ext === 'png' ? 'png' : 'jpeg').';base64,'.base64_encode(Storage::disk('local')->get($caminho));
    }
}
