<?php

namespace App\Licitacao\Nexlicita;

use App\Licitacao\Regras\DiasUteis;
use App\Licitacao\RegraViolada;
use App\Models\Irp;
use App\Models\IrpManifestacao;
use App\Models\Processo;
use App\Models\User;
use App\Support\Parametros;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Intenção de registro de preços (art. 86): o órgão gerenciador abre a IRP, os participantes
 * manifestam suas necessidades, o gerenciador aceita ou recusa e consolida tudo no processo (SRP).
 */
final class RegistroPrecos
{
    public function __construct(private DiasUteis $dias) {}

    /** Prazo mínimo de manifestação, em dias úteis. Vem do regulamento do ente (padrão: 8, ajustável pelo órgão em Parâmetros). */
    public function prazoMinimo(): int
    {
        return (int) Parametros::valor('irp_prazo_minimo_dias_uteis');
    }

    public function abrir(User $u, string $objeto, ?string $justificativa, string $manifestacaoAte, ?int $unidadeId): Irp
    {
        $limite = $this->dias->somar(today(), $this->prazoMinimo());

        if ($manifestacaoAte < $limite->toDateString()) {
            throw new RegraViolada(["O prazo para manifestação precisa ser de pelo menos {$this->prazoMinimo()} dias úteis (até ".$limite->format('d/m/Y').' ou depois).']);
        }

        return DB::transaction(function () use ($u, $objeto, $justificativa, $manifestacaoAte, $unidadeId) {
            $ano = (int) now()->year;
            $numero = str_pad((string) (Irp::where('ano', $ano)->count() + 1), 3, '0', STR_PAD_LEFT);

            return Irp::create([
                'orgao_id' => $u->orgao_id, 'unidade_id' => $unidadeId, 'criada_por' => $u->id, 'ano' => $ano, 'numero' => $numero,
                'objeto' => $objeto, 'justificativa' => $justificativa, 'manifestacao_ate' => $manifestacaoAte,
            ]);
        });
    }

    public function manifestar(Irp $irp, User $u, string $participante, string $descricao, string $unidadeMedida, float $quantidade, ?string $justificativa, ?int $unidadeId): IrpManifestacao
    {
        if (! $irp->aceitaManifestacao()) {
            throw new RegraViolada(['A IRP não está mais aberta para manifestação.']);
        }
        if ($quantidade <= 0) {
            throw new RegraViolada(['A quantidade precisa ser maior que zero.']);
        }

        return IrpManifestacao::create([
            'orgao_id' => $irp->orgao_id, 'irp_id' => $irp->id, 'unidade_id' => $unidadeId, 'participante' => $participante,
            'descricao' => $descricao, 'unidade_medida' => $unidadeMedida, 'quantidade' => $quantidade, 'justificativa' => $justificativa, 'enviada_por' => $u->id,
        ]);
    }

    public function decidir(IrpManifestacao $m, bool $aceita, ?string $motivo): void
    {
        if ($m->irp->situacao === 'consolidada' || $m->irp->situacao === 'cancelada') {
            throw new RegraViolada(['A IRP já foi consolidada ou cancelada.']);
        }
        if (! $aceita && mb_strlen(trim((string) $motivo)) < 10) {
            throw new RegraViolada(['Informe o motivo da recusa (mínimo 10 caracteres).']);
        }

        $m->update($aceita ? ['situacao' => 'aceita', 'motivo_recusa' => null] : ['situacao' => 'recusada', 'motivo_recusa' => trim($motivo)]);
    }

    public function encerrar(Irp $irp): void
    {
        if ($irp->situacao !== 'aberta') {
            throw new RegraViolada(['A IRP não está aberta.']);
        }
        $irp->update(['situacao' => 'encerrada']);
    }

    /** Soma as manifestações aceitas, item a item (mesma descrição e unidade), e cria os itens do processo. */
    public function consolidar(Irp $irp, Processo $p): int
    {
        if (! $p->editavel()) {
            throw new RegraViolada(['O processo precisa estar na fase preparatória.']);
        }
        if (! in_array($irp->situacao, ['aberta', 'encerrada'], true)) {
            throw new RegraViolada(['A IRP já foi consolidada ou cancelada.']);
        }

        $grupos = $irp->manifestacoes()->where('situacao', 'aceita')->get()
            ->groupBy(fn (IrpManifestacao $m) => Str::lower(Str::ascii(trim($m->descricao))).'|'.Str::lower(trim($m->unidade_medida)));

        if ($grupos->isEmpty()) {
            throw new RegraViolada(['Aceite ao menos uma manifestação antes de consolidar.']);
        }

        return DB::transaction(function () use ($irp, $p, $grupos) {
            $numero = (int) $p->itens()->max('numero');

            foreach ($grupos as $g) {
                $p->itens()->create([
                    'orgao_id' => $p->orgao_id, 'numero' => ++$numero, 'material_servico' => 'M', 'descricao' => $g->first()->descricao,
                    'unidade_medida' => $g->first()->unidade_medida, 'quantidade' => $g->sum('quantidade'), 'tipo_beneficio' => 4,
                ]);
            }

            $p->forceFill(['srp' => true])->save();
            $irp->update(['situacao' => 'consolidada', 'processo_id' => $p->id]);

            return $grupos->count();
        });
    }
}
