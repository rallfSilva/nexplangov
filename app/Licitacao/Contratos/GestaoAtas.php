<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\Regras\RegistroDePrecos;
use App\Licitacao\RegraViolada;
use App\Models\Ata;
use App\Models\AtaAdesao;
use App\Models\AtaConsumo;
use App\Models\AtaItem;
use App\Models\Processo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Atas de registro de preços: geração, saldo por item, adesão (carona), prorrogação e cancelamento. */
final class GestaoAtas
{
    public function __construct(private RegistroDePrecos $regras = new RegistroDePrecos) {}

    /** Gera uma ata por fornecedor vencedor, com os itens adjudicados a ele. */
    public function gerarDoProcesso(Processo $p, User $u, string $assinatura, string $inicio, string $fim): int
    {
        $motivos = [];

        if (! $p->srp) {
            $motivos[] = 'Só processo de registro de preços gera ata.';
        }
        if (! in_array($p->situacao, ['homologado', 'contratado'], true)) {
            $motivos[] = 'Homologue o processo antes de gerar as atas (art. 71, V).';
        }
        if (Ata::where('processo_id', $p->id)->exists()) {
            $motivos[] = 'As atas deste processo já foram geradas.';
        }
        $motivos = array_merge($motivos, $this->regras->validarVigencia(CarbonImmutable::parse($inicio), CarbonImmutable::parse($fim)));

        $itens = $p->itens()->where('situacao', 'homologado')->whereNotNull('fornecedor_vencedor_id')->get();
        if ($itens->isEmpty()) {
            $motivos[] = 'Nenhum item homologado com vencedor.';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        return DB::transaction(function () use ($p, $u, $assinatura, $inicio, $fim, $itens) {
            $ano = (int) CarbonImmutable::parse($assinatura)->year;
            $seq = Ata::where('ano', $ano)->count();
            $n = 0;

            foreach ($itens->groupBy('fornecedor_vencedor_id') as $fornecedorId => $grupo) {
                $ata = Ata::create([
                    'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'fornecedor_id' => $fornecedorId, 'criada_por' => $u->id,
                    'ano' => $ano, 'numero' => str_pad((string) ++$seq, 3, '0', STR_PAD_LEFT),
                    'assinatura' => $assinatura, 'vigencia_inicio' => $inicio, 'vigencia_fim' => $fim,
                ]);
                foreach ($grupo as $item) {
                    AtaItem::create(['orgao_id' => $p->orgao_id, 'ata_id' => $ata->id, 'item_id' => $item->id, 'quantidade_registrada' => $item->quantidade, 'valor_unitario' => $item->valor_unitario_homologado]);
                }
                $n++;
            }

            return $n;
        });
    }

    public function registrarConsumo(AtaItem $ai, float $quantidade, string $referencia, ?int $unidadeId, User $u, ?AtaAdesao $adesao = null): AtaConsumo
    {
        $ata = $ai->ata;
        $motivos = [];

        if (! $ata->vigente()) {
            $motivos[] = 'A ata não está vigente: '.$ata->situacaoExibida().'.';
        }
        if ($quantidade <= 0) {
            $motivos[] = 'A quantidade precisa ser maior que zero.';
        }
        if (mb_strlen(trim($referencia)) < 3) {
            $motivos[] = 'Informe a referência (empenho, ordem de fornecimento ou contrato).';
        }

        if ($adesao) {
            if ($adesao->ata_item_id !== $ai->id || $adesao->situacao !== 'autorizada') {
                $motivos[] = 'A adesão não está autorizada para este item.';
            } elseif ($adesao->prazoEncerrado()) {
                $motivos[] = 'O prazo para o aderente contratar terminou em '.$adesao->prazo_contratacao->format('d/m/Y').' (regulamento do art. 86).';
            } else {
                $saldoAdesao = (float) $adesao->quantidade - (float) AtaConsumo::where('adesao_id', $adesao->id)->sum('quantidade');
                if ($quantidade > $saldoAdesao + 0.00001) {
                    $motivos[] = 'Saldo da adesão insuficiente: restam '.number_format($saldoAdesao, 4, ',', '.').'.';
                }
            }
        } elseif ($quantidade > $ai->saldo() + 0.00001) {
            $motivos[] = 'Saldo do item insuficiente: restam '.number_format($ai->saldo(), 4, ',', '.').'.';
        }

        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        return AtaConsumo::create(['orgao_id' => $ai->orgao_id, 'ata_item_id' => $ai->id, 'adesao_id' => $adesao?->id, 'unidade_id' => $unidadeId, 'quantidade' => $quantidade, 'referencia' => trim($referencia), 'user_id' => $u->id]);
    }

    /** Estorno de um consumo (ordem cancelada): novo registro negativo, sem apagar o original. */
    public function estornar(AtaConsumo $c, string $referencia, User $u): AtaConsumo
    {
        if ((float) $c->quantidade <= 0) {
            throw new RegraViolada(['Só se estorna um consumo.']);
        }

        return AtaConsumo::create(['orgao_id' => $c->orgao_id, 'ata_item_id' => $c->ata_item_id, 'adesao_id' => $c->adesao_id, 'unidade_id' => $c->unidade_id, 'quantidade' => -(float) $c->quantidade, 'referencia' => $referencia, 'user_id' => $u->id]);
    }

    public function prorrogar(Ata $ata, string $novoFim, ?string $vantagem): void
    {
        if ($ata->situacao !== 'vigente' || $ata->vigencia_fim->lt(today())) {
            throw new RegraViolada(['Só se prorroga ata vigente. Ata vencida exige nova licitação.']);
        }
        $m = $this->regras->validarProrrogacao($ata->vigencia_inicio, $ata->vigencia_fim, CarbonImmutable::parse($novoFim), $ata->prorrogada, $vantagem);
        if ($m) {
            throw new RegraViolada($m);
        }
        $ata->update(['vigencia_fim' => $novoFim, 'prorrogada' => true, 'fundamento_prorrogacao' => trim((string) $vantagem)]);
    }

    public function cancelar(Ata $ata, string $motivo): void
    {
        if ($ata->situacao === 'cancelada') {
            throw new RegraViolada(['A ata já está cancelada.']);
        }
        if (mb_strlen(trim($motivo)) < 10) {
            throw new RegraViolada(['Informe o motivo do cancelamento (mínimo 10 caracteres).']);
        }
        $ata->update(['situacao' => 'cancelada', 'motivo_cancelamento' => trim($motivo)]);
    }
}
