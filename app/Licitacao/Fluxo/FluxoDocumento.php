<?php

namespace App\Licitacao\Fluxo;

use App\Licitacao\Regras\InstrucaoProcesso;
use App\Licitacao\RegraViolada;
use App\Licitacao\TiposDocumento;
use App\Models\DocumentoProcesso;
use App\Models\EtapaProcesso;
use App\Models\Fluxo;
use App\Models\Processo;
use App\Models\SolicitacaoExterna;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fluxo configurável por documento. Para cada peça o órgão define dependências, prazo, revisão,
 * aprovação, assinatura, responsável e substituto. Uma peça só é liberada quando as anteriores
 * terminam, e a mesma pessoa não revisa nem aprova o que escreveu (segregação de funções).
 *
 * Situações da peça: rascunho, em_revisao, em_aprovacao, aguardando_assinatura, concluido.
 */
final class FluxoDocumento
{
    /** Permissão que cada peça exige de quem a elabora. As demais pedem processos.gerenciar. */
    public const PERMISSAO = ['parecer_juridico' => 'processos.parecer', 'autorizacao' => 'processos.autorizar'];

    public const ORIGENS = ['formulario' => 'Preenchida no sistema', 'arquivo' => 'Arquivo enviado pronto', 'solicitado' => 'Pedida a outro responsável'];

    public const ESTADOS = [
        'bloqueada' => ['Bloqueada', 'b-mute'],
        'disponivel' => ['Disponível', 'b-info'],
        'em_elaboracao' => ['Em elaboração', 'b-warn'],
        'em_revisao' => ['Em revisão', 'b-warn'],
        'em_aprovacao' => ['Em aprovação', 'b-warn'],
        'aguardando_assinatura' => ['Aguardando assinatura', 'b-warn'],
        'concluida' => ['Concluída', 'b-ok'],
    ];

    /** Fluxo mais específico do órgão para o processo: o da categoria, depois o da forma (licitação ou direta), depois o geral. */
    public function fluxoPadraoPara(Processo $p): ?Fluxo
    {
        $aplicacao = $p->modalidadeEnum()->ehContratacaoDireta() ? 'direta' : 'licitacao';

        return Fluxo::where('orgao_id', $p->orgao_id)->where('ativo', true)->where('padrao', true)
            ->whereIn('aplicacao', ['todos', $aplicacao])
            ->where(fn ($q) => $q->whereNull('categoria')->orWhere('categoria', $p->categoria))
            ->get()
            ->sortByDesc(fn (Fluxo $f) => [($f->categoria === $p->categoria ? 2 : 0) + ($f->aplicacao !== 'todos' ? 1 : 0), (int) $f->padrao])
            ->first();
    }

    /** Congela no processo as etapas do fluxo que valem para a forma de contratação dele. */
    public function aplicar(Processo $p, ?Fluxo $fluxo = null): int
    {
        $fluxo ??= $this->fluxoPadraoPara($p);

        if ($fluxo === null) {
            return 0;
        }

        $aplicaveis = collect(app(InstrucaoProcesso::class)->checklist($p))->pluck('chave')->filter(fn ($c) => array_key_exists($c, TiposDocumento::todos()))->all();

        return DB::transaction(function () use ($p, $fluxo, $aplicaveis) {
            $p->etapas()->delete();
            $n = 0;

            foreach ($fluxo->etapas as $e) {
                if (! in_array($e->tipo, $aplicaveis, true)) {
                    continue;
                }

                EtapaProcesso::create([
                    'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'tipo' => $e->tipo, 'ordem' => $e->ordem,
                    'depende_de' => array_values(array_intersect($e->depende_de ?? [], $aplicaveis)), 'prazo_dias' => $e->prazo_dias,
                    'exige_revisao' => $e->exige_revisao, 'exige_aprovacao' => $e->exige_aprovacao, 'exige_assinatura' => $e->exige_assinatura,
                    'origem' => $e->origem, 'responsavel_id' => $e->responsavel_id, 'substituto_id' => $e->substituto_id,
                    'revisor_id' => $e->revisor_id, 'aprovador_id' => $e->aprovador_id, 'assinantes' => $e->assinantes ?? [],
                    'solicitado_em' => $e->origem === 'solicitado' && $e->responsavel_id ? now() : null,
                ]);
                $n++;
            }

            $p->forceFill(['fluxo_id' => $fluxo->id])->save();
            $this->sincronizarPrazos($p->refresh());

            return $n;
        });
    }

    public function etapa(Processo $p, string $tipo): ?EtapaProcesso
    {
        return $p->etapas->firstWhere('tipo', $tipo) ?? $p->etapas()->where('tipo', $tipo)->first();
    }

    /** @return list<string> peças de que esta depende e que ainda não foram concluídas */
    public function dependenciasPendentes(Processo $p, string $tipo): array
    {
        $etapa = $this->etapa($p, $tipo);

        return collect($etapa?->depende_de ?? [])
            ->reject(fn ($dep) => $p->documentoConcluido($dep))
            ->map(fn ($dep) => TiposDocumento::get($dep)['rotulo'])
            ->values()->all();
    }

    /** @return array{codigo: string, rotulo: string, classe: string, pendencias: list<string>} */
    public function estado(Processo $p, string $tipo): array
    {
        $atual = $p->documentoAtual($tipo);
        $pend = $this->dependenciasPendentes($p, $tipo);

        $codigo = match (true) {
            $atual?->situacao === 'concluido' => 'concluida',
            $atual?->situacao === 'rascunho' => $pend ? 'bloqueada' : 'em_elaboracao',
            $atual !== null && $atual->situacao !== 'substituido' => $atual->situacao,
            $pend !== [] => 'bloqueada',
            default => 'disponivel',
        };

        return ['codigo' => $codigo, 'rotulo' => self::ESTADOS[$codigo][0], 'classe' => self::ESTADOS[$codigo][1], 'pendencias' => $pend];
    }

    /** Dá prazo às etapas que acabaram de ser liberadas. */
    public function sincronizarPrazos(Processo $p): void
    {
        foreach ($p->etapas()->whereNotNull('prazo_dias')->whereNull('prazo_em')->get() as $etapa) {
            if ($this->dependenciasPendentes($p, $etapa->tipo) === []) {
                $etapa->update(['prazo_em' => now()->addDays($etapa->prazo_dias)->toDateString()]);
            }
        }
    }

    public function podeElaborar(User $u, Processo $p, string $tipo): bool
    {
        if (! $u->can(self::PERMISSAO[$tipo] ?? 'processos.gerenciar')) {
            return false;
        }

        $naEtapa = match ($tipo) {
            'parecer_juridico' => $p->situacao === 'analise_juridica',
            'autorizacao' => $p->situacao === 'aguardando_autorizacao',
            default => $p->editavel(),
        };

        if (! $naEtapa) {
            return false;
        }

        $e = $this->etapa($p, $tipo);

        return $e === null || $e->responsavel_id === null || $u->ehAdministradorGeral() || in_array($u->id, [$e->responsavel_id, $e->substituto_id], true);
    }

    /** Para onde a peça vai depois de pronta: revisão, aprovação, assinatura ou direto à conclusão. */
    public function destino(?EtapaProcesso $e, string $apos = 'rascunho'): string
    {
        return match (true) {
            $apos === 'rascunho' && $e?->exige_revisao => 'em_revisao',
            in_array($apos, ['rascunho', 'em_revisao'], true) && $e?->exige_aprovacao => 'em_aprovacao',
            in_array($apos, ['rascunho', 'em_revisao', 'em_aprovacao'], true) && $e?->exige_assinatura => 'aguardando_assinatura',
            default => 'concluido',
        };
    }

    /** Envia a peça pronta para a próxima porta do fluxo. Devolve a nova situação. */
    public function encaminhar(DocumentoProcesso $d, User $autor): string
    {
        $p = $d->processo;
        $this->exigirLiberada($p, $d->tipo);
        $dest = $this->destino($this->etapa($p, $d->tipo));

        return $this->mover($d, $dest);
    }

    public function podeDecidir(User $u, DocumentoProcesso $d): bool
    {
        if (! in_array($d->situacao, ['em_revisao', 'em_aprovacao'], true) || $u->id === $d->autor_id) {
            return false;
        }

        $perm = self::PERMISSAO[$d->tipo] ?? null;
        $etapa = $this->etapa($d->processo, $d->tipo);

        if ($d->situacao === 'em_revisao') {
            $designado = $etapa?->revisor_id;

            return ($designado === null || $designado === $u->id) && $u->can($perm ?? 'processos.gerenciar');
        }

        $designado = $etapa?->aprovador_id;

        return $u->id !== $d->revisor_id
            && ($designado === null || $designado === $u->id)
            && ($u->can($perm ?? 'processos.gerenciar') || ($perm === null && $u->can('processos.autorizar')));
    }

    /** Revisor ou aprovador aprova a peça, que segue para a próxima porta. */
    public function aprovar(DocumentoProcesso $d, User $u): string
    {
        $this->exigirDecisor($u, $d);
        $etapa = $d->situacao === 'em_revisao' ? ['revisor_id' => $u->id, 'revisado_em' => now()] : ['aprovador_id' => $u->id, 'aprovado_em' => now()];
        $dest = $this->destino($this->etapa($d->processo, $d->tipo), $d->situacao);
        $d->forceFill($etapa);

        return $this->mover($d, $dest);
    }

    /** Devolve a peça ao autor, com motivo, para ajustes. */
    public function devolver(DocumentoProcesso $d, User $u, string $motivo): void
    {
        if (strlen(trim($motivo)) < 10) {
            throw new RegraViolada(['Informe o motivo da devolução (mínimo 10 caracteres).']);
        }

        if ($d->situacao === 'aguardando_assinatura') {
            if (! $this->podeAssinar($u, $d)) {
                throw new RegraViolada(['Só quem deve assinar pode recusar a assinatura.']);
            }
        } else {
            $this->exigirDecisor($u, $d);
        }

        DB::transaction(function () use ($d, $motivo) {
            // Com assinatura já dada o texto não pode voltar a ser editado: nasce uma versão nova.
            if ($d->assinaturas()->exists()) {
                $d->forceFill(['situacao' => 'substituido'])->save();
                DocumentoProcesso::create([
                    'orgao_id' => $d->orgao_id, 'processo_id' => $d->processo_id, 'tipo' => $d->tipo, 'versao' => $d->versao + 1,
                    'conteudo' => $d->conteudo, 'situacao' => 'rascunho', 'autor_id' => $d->autor_id, 'origem' => $d->origem,
                    'arquivo_caminho' => $d->arquivo_caminho, 'arquivo_nome' => $d->arquivo_nome, 'arquivo_tamanho' => $d->arquivo_tamanho, 'arquivo_hash' => $d->arquivo_hash,
                    'motivo_devolucao' => $motivo,
                ]);

                return;
            }

            $d->forceFill(['situacao' => 'rascunho', 'motivo_devolucao' => $motivo, 'revisor_id' => null, 'revisado_em' => null, 'aprovador_id' => null, 'aprovado_em' => null])->save();
        });
    }

    public function podeAssinar(User $u, DocumentoProcesso $d): bool
    {
        if ($d->situacao !== 'aguardando_assinatura') {
            return false;
        }

        return in_array($u->id, $this->assinantes($d), true);
    }

    /** @return list<int> quem precisa assinar: os do fluxo ou, sem lista, o próprio autor */
    public function assinantes(DocumentoProcesso $d): array
    {
        $lista = $this->etapa($d->processo, $d->tipo)?->assinantes ?? [];

        return array_values(array_map('intval', $lista ?: array_filter([$d->autor_id])));
    }

    /** Chamado depois de cada assinatura: com todas as assinaturas, a peça conclui. */
    public function concluirSeAssinada(DocumentoProcesso $d): bool
    {
        $assinaram = $d->assinaturas()->pluck('user_id')->all();

        if (array_diff($this->assinantes($d), $assinaram) !== []) {
            return false;
        }

        // Pedido de assinatura a pessoa de fora ainda aberto também segura a conclusão.
        if (SolicitacaoExterna::where('documento_id', $d->id)->where('tipo', 'assinatura')->whereNull('respondida_em')->whereNull('cancelada_em')->exists()) {
            return false;
        }

        $this->mover($d, 'concluido');

        return true;
    }

    private function mover(DocumentoProcesso $d, string $dest): string
    {
        if ($dest === 'concluido') {
            $d->concluir();
            $this->sincronizarPrazos($d->processo->refresh());
        } else {
            $d->forceFill(['situacao' => $dest])->save();
        }

        return $dest;
    }

    private function exigirLiberada(Processo $p, string $tipo): void
    {
        if ($pend = $this->dependenciasPendentes($p, $tipo)) {
            throw new RegraViolada(array_map(fn ($r) => "Conclua antes: {$r}.", $pend));
        }
    }

    private function exigirDecisor(User $u, DocumentoProcesso $d): void
    {
        if (! $this->podeDecidir($u, $d)) {
            throw new RegraViolada(['Você não pode decidir esta peça: quem revisa ou aprova não pode ser quem a escreveu e precisa ser a pessoa indicada no fluxo.']);
        }
    }

    public function exigirPodeElaborar(User $u, Processo $p, string $tipo): void
    {
        $this->exigirLiberada($p, $tipo);

        if (! $this->podeElaborar($u, $p, $tipo)) {
            throw new RegraViolada(['Você não pode elaborar esta peça agora: ela é de outro responsável ou o processo já passou desta etapa.']);
        }
    }
}
