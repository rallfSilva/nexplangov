<?php

namespace App\Jobs;

use App\Integracoes\Devolucao\PacoteDadosOrgao;
use App\Models\PacoteDevolucao;
use App\Support\Auditoria;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Gera o pacote de devolução em segundo plano: órgão com muitos anos de dados pode levar minutos. */
class GerarPacoteDevolucao implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $pacoteId) {}

    public function handle(PacoteDadosOrgao $gerador): void
    {
        $pacote = PacoteDevolucao::withoutGlobalScope('orgao')->find($this->pacoteId);
        if (! $pacote || $pacote->situacao !== 'na_fila') {
            return;
        }
        $pacote->forceFill(['situacao' => 'gerando'])->save();

        $caminho = "devolucao/{$pacote->orgao_id}/".now()->format('Ymd-His').'-'.Str::random(8).'.zip';
        $disco = Storage::disk('local');
        File::ensureDirectoryExists(dirname($disco->path($caminho)));

        try {
            $r = $gerador->gerar($pacote->orgao()->withoutGlobalScopes()->firstOrFail(), $disco->path($caminho), $pacote->usuario);
            $pacote->forceFill([
                'situacao' => 'pronto', 'arquivo_caminho' => $caminho, 'tamanho' => $r['tamanho'], 'sha256' => $r['sha256'],
                'resumo' => $r['resumo'], 'expira_em' => now()->addDays(PacoteDevolucao::DIAS_DISPONIVEL),
            ])->save();
            Auditoria::registrar('exportacao', $pacote, 'Pacote de devolução de dados gerado ('.array_sum($r['resumo']['tabelas']).' registros, '.$r['resumo']['arquivos'].' arquivos)');
        } catch (\Throwable $e) {
            $disco->delete($caminho);
            $pacote->forceFill(['situacao' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 1000)])->save();
            report($e);
        }
    }
}
