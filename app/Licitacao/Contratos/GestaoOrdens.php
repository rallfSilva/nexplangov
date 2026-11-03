<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\RegraViolada;
use App\Models\AtaItem;
use App\Models\OrdemFornecimento;
use App\Models\Recebimento;
use App\Models\Requisicao;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Requisição, ordem de fornecimento e recebimento sobre o saldo da ata de registro de preços.
 * A ordem consome o saldo da ata; o recebimento provisório e o definitivo seguem o art. 140.
 */
final class GestaoOrdens
{
    public function __construct(private GestaoAtas $atas) {}

    public function requisitar(AtaItem $ai, float $quantidade, ?int $unidadeId, string $justificativa, User $u): Requisicao
    {
        $m = [];

        if (! $ai->ata->vigente()) {
            $m[] = 'A ata não está vigente.';
        }
        if ($quantidade <= 0) {
            $m[] = 'A quantidade precisa ser maior que zero.';
        }
        if ($quantidade > $ai->saldo() + 0.00001) {
            $m[] = 'Saldo do item insuficiente: restam '.number_format($ai->saldo(), 4, ',', '.').'.';
        }
        if (mb_strlen(trim($justificativa)) < 10) {
            $m[] = 'Justifique a necessidade (mínimo 10 caracteres).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return Requisicao::create(['orgao_id' => $ai->orgao_id, 'ata_item_id' => $ai->id, 'unidade_id' => $unidadeId, 'requisitante_id' => $u->id, 'quantidade' => $quantidade, 'justificativa' => trim($justificativa)]);
    }

    public function decidir(Requisicao $r, bool $aprovar, ?string $motivo, User $u): void
    {
        $m = [];

        if ($r->situacao !== 'solicitada') {
            $m[] = 'Esta requisição já foi decidida.';
        }
        if ($r->requisitante_id === $u->id) {
            $m[] = 'Quem requisita não aprova a própria requisição (segregação de funções, art. 7º, § 1º).';
        }
        if (! $aprovar && mb_strlen(trim((string) $motivo)) < 10) {
            $m[] = 'Informe o motivo da recusa (mínimo 10 caracteres).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        $r->update(['situacao' => $aprovar ? 'aprovada' : 'recusada', 'motivo_decisao' => $motivo ? trim($motivo) : null, 'decidida_por' => $u->id, 'decidida_em' => now()]);
    }

    /** Emite a ordem a partir de uma requisição aprovada. Consome o saldo da ata. */
    public function emitir(Requisicao $r, string $prazoEntrega, User $u): OrdemFornecimento
    {
        $ai = $r->ataItem;
        $m = [];

        if ($r->situacao !== 'aprovada') {
            $m[] = 'Só se emite ordem de requisição aprovada.';
        }
        if ($prazoEntrega < today()->toDateString()) {
            $m[] = 'O prazo de entrega não pode estar no passado.';
        }
        if ($sancao = $ai->ata->fornecedor->impedidoEm()) {
            $m[] = "{$ai->ata->fornecedor->razao_social} está impedido de contratar até ".($sancao->fim?->format('d/m/Y') ?? 'decisão em contrário').' (art. 156).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return DB::transaction(function () use ($r, $ai, $prazoEntrega, $u) {
            $ano = (int) now()->year;
            $numero = str_pad((string) (OrdemFornecimento::where('ano', $ano)->count() + 1), 4, '0', STR_PAD_LEFT);
            $consumo = $this->atas->registrarConsumo($ai, (float) $r->quantidade, "OF {$numero}/{$ano}", $r->unidade_id, $u);

            $ordem = OrdemFornecimento::create([
                'orgao_id' => $r->orgao_id, 'ata_item_id' => $ai->id, 'requisicao_id' => $r->id, 'consumo_id' => $consumo->id, 'unidade_id' => $r->unidade_id,
                'emitida_por' => $u->id, 'ano' => $ano, 'numero' => $numero, 'quantidade' => $r->quantidade, 'valor_unitario' => $ai->valor_unitario,
                'valor_total' => round((float) $r->quantidade * (float) $ai->valor_unitario, 2), 'prazo_entrega' => $prazoEntrega,
            ]);
            $r->update(['situacao' => 'atendida']);

            return $ordem;
        });
    }

    public function receber(OrdemFornecimento $o, string $tipo, float $quantidade, string $documento, string $data, ?string $observacao, User $u): Recebimento
    {
        $m = [];

        if (! in_array($tipo, ['provisorio', 'definitivo'], true)) {
            $m[] = 'Tipo de recebimento inválido (art. 140).';
        }
        if (in_array($o->situacao, ['entregue', 'cancelada'], true)) {
            $m[] = 'A ordem está '.mb_strtolower(OrdemFornecimento::SITUACOES[$o->situacao]).'.';
        }
        if ($quantidade <= 0) {
            $m[] = 'A quantidade precisa ser maior que zero.';
        }
        if (mb_strlen(trim($documento)) < 2) {
            $m[] = 'Informe a nota fiscal ou o termo do recebimento.';
        }
        if ($data > today()->toDateString()) {
            $m[] = 'A data do recebimento não pode ser futura.';
        }

        if ($tipo === 'provisorio' && $quantidade > (float) $o->quantidade - $o->recebido('provisorio') + 0.00001) {
            $m[] = 'A quantidade passa do que falta receber da ordem.';
        }
        if ($tipo === 'definitivo') {
            if ($quantidade > $o->recebido('provisorio') - $o->recebido('definitivo') + 0.00001) {
                $m[] = 'O recebimento definitivo não pode passar do que já foi recebido provisoriamente.';
            }
            if ($u->id === $o->emitida_por) {
                $m[] = 'Quem emitiu a ordem não faz o recebimento definitivo (segregação de funções).';
            }
            if ($o->recebimentos()->where('tipo', 'provisorio')->where('recebido_por', $u->id)->exists()) {
                $m[] = 'O recebimento definitivo é feito por servidor ou comissão diferente de quem recebeu provisoriamente (art. 140).';
            }
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return DB::transaction(function () use ($o, $tipo, $quantidade, $documento, $data, $observacao, $u) {
            $r = Recebimento::create(['orgao_id' => $o->orgao_id, 'ordem_id' => $o->id, 'tipo' => $tipo, 'quantidade' => $quantidade, 'documento' => trim($documento), 'data' => $data, 'observacao' => $observacao, 'recebido_por' => $u->id]);
            $definitivo = $o->recebido('definitivo');
            $o->update(['situacao' => $definitivo + 0.00001 >= (float) $o->quantidade ? 'entregue' : ($definitivo > 0 || $o->recebido('provisorio') > 0 ? 'parcial' : 'emitida')]);

            return $r;
        });
    }

    public function cancelar(OrdemFornecimento $o, string $motivo, User $u): void
    {
        $m = [];

        if (! in_array($o->situacao, ['emitida'], true)) {
            $m[] = 'Só se cancela ordem sem nenhum recebimento.';
        }
        if (mb_strlen(trim($motivo)) < 10) {
            $m[] = 'Informe o motivo do cancelamento (mínimo 10 caracteres).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        DB::transaction(function () use ($o, $motivo, $u) {
            if ($o->consumo) {
                $this->atas->estornar($o->consumo, "Estorno da OF {$o->identificacao()}", $u);
            }
            $o->update(['situacao' => 'cancelada', 'motivo_cancelamento' => trim($motivo)]);
            $o->requisicao?->update(['situacao' => 'cancelada']);
        });
    }
}
