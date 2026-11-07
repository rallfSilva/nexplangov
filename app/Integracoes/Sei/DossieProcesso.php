<?php

namespace App\Integracoes\Sei;

use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\TiposDocumento;
use App\Models\Processo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Dossiê do processo para juntar no SEI (ou outro protocolo): uma capa, cada peça concluída em HTML
 * (ou o arquivo original enviado), a folha de assinaturas e um manifesto com o SHA-256 de cada arquivo.
 * No SEI, cada arquivo entra como "documento externo"; o manifesto prova que nada mudou no caminho.
 */
final class DossieProcesso
{
    public function __construct(private Assinaturas $assinaturas) {}

    public function gerar(Processo $p, string $destino): string
    {
        $zip = new ZipArchive;
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o dossiê.');
        }

        $manifesto = ['processo' => $p->identificacao(), 'numero_sei' => $p->numero_sei, 'orgao' => $p->orgao?->cnpj, 'gerado_em' => now()->toIso8601String(), 'arquivos' => []];
        $add = function (string $nome, string $conteudo) use ($zip, &$manifesto) {
            $zip->addFromString($nome, $conteudo);
            $manifesto['arquivos'][$nome] = hash('sha256', $conteudo);
        };

        $pecas = $p->documentos()->where('situacao', 'concluido')->with('assinaturas')->orderBy('id')->get()
            ->groupBy('tipo')->map(fn ($v) => $v->sortByDesc('versao')->first())->sortBy('id')->values();

        $indice = [];
        foreach ($pecas as $i => $d) {
            $def = TiposDocumento::get($d->tipo);
            $base = sprintf('%02d-%s-v%d', $i + 1, $d->tipo, $d->versao);

            if ($d->origem === 'arquivo' && $d->arquivo_caminho && Storage::disk('local')->exists($d->arquivo_caminho)) {
                $ext = pathinfo((string) $d->arquivo_nome, PATHINFO_EXTENSION) ?: 'bin';
                $nome = "{$base}.".preg_replace('/[^a-z0-9]/', '', strtolower($ext));
                $add($nome, Storage::disk('local')->get($d->arquivo_caminho));
            } else {
                $nome = "{$base}.html";
                $add($nome, $this->html($p, $def, $d));
            }
            $indice[] = ['arquivo' => $nome, 'rotulo' => $def['rotulo'] ?? $d->tipo, 'base' => $def['base'] ?? '', 'versao' => $d->versao, 'hash' => $d->hashAtual(), 'assinaturas' => $d->assinaturas->count()];
        }

        // Documentos juntados livremente (os desentranhados ficam de fora).
        foreach ($p->juntadas()->whereNull('desentranhado_em')->get() as $k => $j) {
            if (! Storage::disk('local')->exists($j->arquivo_caminho)) {
                continue;
            }
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($j->arquivo_nome, PATHINFO_EXTENSION))) ?: 'bin';
            $nome = sprintf('J%02d-%s.%s', $k + 1, Str::slug(Str::limit($j->titulo, 50, '')), $ext);
            $add($nome, Storage::disk('local')->get($j->arquivo_caminho));
            $indice[] = ['arquivo' => $nome, 'rotulo' => $j->titulo, 'base' => 'Juntada', 'versao' => 1, 'hash' => $j->arquivo_hash, 'assinaturas' => 0];
        }

        $add('00-capa.html', $this->capa($p, $indice));
        $zip->addFromString('manifesto.json', json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();

        return $destino;
    }

    private function pagina(string $titulo, string $corpo): string
    {
        $t = e($titulo);

        return "<!DOCTYPE html><html lang=\"pt-BR\"><head><meta charset=\"utf-8\"><title>{$t}</title><style>body{font-family:Arial,sans-serif;color:#1b2a35;max-width:820px;margin:24px auto;padding:0 20px;line-height:1.55}h1{color:#1E5470;font-size:20px}h2{color:#1E5470;font-size:15px;margin-top:22px}.m{color:#4a5b68;font-size:12px}table{border-collapse:collapse;width:100%;font-size:13px}td,th{border:1px solid #CBEAEC;padding:6px 8px;text-align:left}.h{font-family:monospace;font-size:11px;word-break:break-all}</style></head><body>{$corpo}</body></html>";
    }

    private function html(Processo $p, ?array $def, $d): string
    {
        $corpo = '<h1>'.e($def['rotulo'] ?? $d->tipo).'</h1><p class="m">Processo '.e($p->identificacao()).' · '.e($def['base'] ?? '').' · versão '.$d->versao.' · concluída em '.e($d->concluido_em?->format('d/m/Y H:i') ?? '').'</p>';
        foreach (($def['campos'] ?? []) as $campo => $c) {
            $v = trim((string) ($d->conteudo[$campo] ?? ''));
            $corpo .= '<h2>'.e($c['rotulo']).'</h2><p>'.($v === '' ? '<i>Não preenchido.</i>' : nl2br(e($v))).'</p>';
        }
        if ($d->assinaturas->isNotEmpty()) {
            $corpo .= '<h2>Assinaturas eletrônicas</h2><table><tr><th>Nome</th><th>Perfil</th><th>Data</th><th>Código</th></tr>';
            foreach ($d->assinaturas->sortBy('assinado_em') as $a) {
                $corpo .= '<tr><td>'.e($a->nome).'</td><td>'.e($a->perfil).'</td><td>'.e($a->assinado_em->format('d/m/Y H:i')).'</td><td>'.e(Assinaturas::formatarCodigo($a->codigo)).'</td></tr>';
            }
            $corpo .= '</table><p class="m">Confira em '.e(route('assinaturas.validar')).'</p>';
        }
        $corpo .= '<p class="m">Impressão digital da peça (SHA-256): <span class="h">'.e($d->hashAtual()).'</span></p>';

        return $this->pagina($def['rotulo'] ?? $d->tipo, $corpo);
    }

    private function capa(Processo $p, array $indice): string
    {
        $corpo = '<h1>Dossiê do processo '.e($p->identificacao()).'</h1><p class="m">'.e($p->orgao?->nome ?? '').($p->numero_sei ? ' · SEI '.e($p->numero_sei) : '').' · gerado em '.e(now()->format('d/m/Y H:i')).'</p>'
            .'<p><b>Objeto:</b> '.e($p->objeto).'</p><p><b>Modalidade:</b> '.e($p->modalidadeEnum()->rotulo()).' · <b>Situação:</b> '.e($p->situacaoEnum()->rotulo()).'</p>'
            .'<h2>Peças</h2><table><tr><th>Arquivo</th><th>Peça</th><th>Base legal</th><th>Versão</th><th>Assinaturas</th></tr>';
        foreach ($indice as $i) {
            $corpo .= '<tr><td>'.e($i['arquivo']).'</td><td>'.e($i['rotulo']).'</td><td>'.e($i['base']).'</td><td>'.$i['versao'].'</td><td>'.$i['assinaturas'].'</td></tr>';
        }
        $corpo .= '</table><p class="m">O arquivo manifesto.json traz o SHA-256 de cada arquivo deste dossiê.</p>';

        return $this->pagina('Dossiê '.$p->identificacao(), $corpo);
    }
}
