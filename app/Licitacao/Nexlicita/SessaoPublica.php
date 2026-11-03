<?php

namespace App\Licitacao\Nexlicita;

use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Regras\PrazosRecurso;
use App\Licitacao\RegraViolada;
use App\Models\Processo;
use App\Models\User;
use App\Support\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registro da sessão pública: gravação em áudio e vídeo (obrigatória na forma presencial, art. 17, §§ 2º e 5º),
 * encerramento com lavratura da ata (marco do prazo das razões de recurso, art. 165, § 1º, I) e reabertura motivada.
 */
final class SessaoPublica
{
    public function __construct(private PrazosRecurso $prazos) {}

    public function registrarGravacao(Processo $p, ?string $link, ?UploadedFile $arquivo): void
    {
        $this->exigirLicitacao($p);
        $link = trim((string) $link);

        if ($link === '' && ! $arquivo) {
            throw new RegraViolada(['Informe o endereço da gravação ou envie o arquivo.']);
        }
        if ($link !== '' && ! preg_match('~^https://~i', $link)) {
            throw new RegraViolada(['O endereço da gravação precisa começar com https://.']);
        }

        $dados = ['gravacao_link' => $link ?: $p->gravacao_link];
        if ($arquivo) {
            $ext = strtolower($arquivo->getClientOriginalExtension() ?: 'mp4');
            $caminho = $arquivo->storeAs("orgaos/{$p->orgao_id}/processos/{$p->id}/sessao", Str::uuid().'.'.$ext, 'local');
            if ($p->gravacao_caminho) {
                Storage::disk('local')->delete($p->gravacao_caminho);
            }
            $dados += [
                'gravacao_caminho' => $caminho,
                'gravacao_nome' => mb_substr($arquivo->getClientOriginalName(), 0, 160),
                'gravacao_hash' => hash_file('sha256', $arquivo->getRealPath()),
            ];
        }

        $p->forceFill($dados)->save();
    }

    /** Encerra a sessão e lavra a ata. Abre o prazo de 3 dias úteis para as razões de cada intenção de recurso. */
    public function encerrar(Processo $p, User $u): void
    {
        $this->exigirLicitacao($p);
        $motivos = [];

        if ($p->sessaoEncerrada()) {
            $motivos[] = 'A sessão já foi encerrada.';
        }
        if ($p->modalidadeEnum()->ehPresencial() && ! $p->temGravacao()) {
            $motivos[] = 'Na forma presencial, registre a gravação da sessão em áudio e vídeo antes de encerrar (art. 17, § 5º).';
        }
        if (! $p->itens()->whereHas('propostas')->exists()) {
            $motivos[] = 'Nenhuma proposta foi registrada.';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        DB::transaction(function () use ($p, $u) {
            $agora = now();
            $p->forceFill(['sessao_encerrada_em' => $agora, 'sessao_encerrada_por' => $u->id])->save();

            $prazo = $this->prazos->razoes($agora)->toDateString();
            $p->recursos()->where('situacao', 'intencao')->whereNull('prazo_razoes')->update(['prazo_razoes' => $prazo]);

            $this->lavrarAta($p->refresh());
        });
    }

    /** Reabre a sessão (por exemplo, para refazer a habilitação depois de recurso provido). A ata anterior fica guardada. */
    public function reabrir(Processo $p, string $motivo): void
    {
        $this->exigirLicitacao($p);

        if (! $p->sessaoEncerrada()) {
            throw new RegraViolada(['A sessão está aberta.']);
        }
        if (mb_strlen(trim($motivo)) < 20) {
            throw new RegraViolada(['Justifique a reabertura (mínimo 20 caracteres). A justificativa fica no processo.']);
        }

        $p->forceFill(['sessao_encerrada_em' => null, 'sessao_encerrada_por' => null, 'sessao_reaberta_motivo' => trim($motivo)])->save();
    }

    private function lavrarAta(Processo $p): void
    {
        $p->load(['orgao', 'itens' => fn ($q) => $q->orderBy('numero'), 'itens.propostas.fornecedor', 'itens.propostas.lances', 'recursos.fornecedor', 'recursos.item']);
        $encerrou = User::find($p->sessao_encerrada_por);

        $conteudo = Pdf::daView('licitacao.ata-sessao-pdf', ['p' => $p, 'encerrou' => $encerrou]);
        $caminho = "orgaos/{$p->orgao_id}/processos/{$p->id}/sessao/ata-".$p->sessao_encerrada_em->format('Ymd-His').'.pdf';
        Storage::disk('local')->put($caminho, $conteudo);

        $p->forceFill(['ata_sessao_caminho' => $caminho, 'ata_sessao_hash' => hash('sha256', $conteudo)])->save();
    }

    private function exigirLicitacao(Processo $p): void
    {
        if (Modalidade::from($p->modalidade)->ehContratacaoDireta()) {
            throw new RegraViolada(['Contratação direta não tem sessão pública.']);
        }
        if ($p->situacao !== 'em_julgamento') {
            throw new RegraViolada(['A sessão só é registrada com o processo em julgamento.']);
        }
    }
}
