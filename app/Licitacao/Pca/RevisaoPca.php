<?php

namespace App\Licitacao\Pca;

use App\Licitacao\Regras\VersoesPca;
use App\Licitacao\RegraViolada;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\User;
use App\Models\VersaoPca;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;

/**
 * Aprovação e revisão do PCA (art. 12, VII e § 1º). Cada aprovação gera uma versão imutável dos itens;
 * depois de aprovado, o plano só muda por revisão aberta com justificativa e aprovada de novo.
 */
final class RevisaoPca
{
    public function aprovar(PlanoContratacao $plano, User $autoridade): VersaoPca
    {
        if ($plano->situacao !== 'em_aprovacao') {
            throw new RegraViolada(['Este PCA não está aguardando aprovação.']);
        }

        return DB::transaction(function () use ($plano, $autoridade) {
            $itens = $plano->copiaDosItens();
            $numero = $plano->versao_atual + 1;
            $versao = VersaoPca::create([
                'orgao_id' => $plano->orgao_id, 'plano_contratacao_id' => $plano->id, 'numero' => $numero,
                'justificativa' => $plano->revisao_justificativa, 'itens' => $itens, 'qtd_itens' => count($itens),
                'valor_total' => VersoesPca::total($itens), 'aberta_por' => $plano->revisao_aberta_por,
                'aprovado_por' => $autoridade->id, 'aprovado_em' => now(),
            ]);

            $plano->forceFill([
                'situacao' => 'aprovado', 'aprovado_por' => $autoridade->id, 'aprovado_em' => now(), 'versao_atual' => $numero,
                'revisao_justificativa' => null, 'revisao_aberta_por' => null, 'revisao_aberta_em' => null,
            ])->save();

            return $versao;
        });
    }

    public function abrir(PlanoContratacao $plano, User $usuario, ?string $justificativa): void
    {
        $motivos = [];
        if ($plano->situacao !== 'aprovado') {
            $motivos[] = 'Só o PCA aprovado recebe revisão; antes disso, edite os itens normalmente.';
        }
        if (! VersoesPca::justificativaValida($justificativa)) {
            $motivos[] = 'Explique o motivo da revisão em pelo menos '.VersoesPca::JUSTIFICATIVA_MINIMA.' caracteres.';
        }
        if ($motivos !== []) {
            throw new RegraViolada($motivos);
        }

        $plano->forceFill([
            'situacao' => 'rascunho', 'revisao_justificativa' => trim((string) $justificativa),
            'revisao_aberta_por' => $usuario->id, 'revisao_aberta_em' => now(),
        ])->save();
        Auditoria::registrar('revisao', $plano, "Revisão do PCA {$plano->ano} aberta sobre a versão {$plano->versao_atual}", null, ['justificativa' => $plano->revisao_justificativa]);
    }

    /** Desiste da revisão: os itens voltam a ser os da versão vigente e o plano volta a aprovado. */
    public function descartar(PlanoContratacao $plano): void
    {
        $vigente = $plano->versaoVigente();
        if (! $plano->emRevisao() || ! $vigente) {
            throw new RegraViolada(['Não há revisão aberta neste PCA.']);
        }

        DB::transaction(function () use ($plano, $vigente) {
            $copia = collect($vigente->itens)->keyBy('numero');
            foreach ($plano->itens()->get() as $item) {
                if (! $copia->has($item->numero)) {
                    $item->delete();
                }
            }
            foreach ($copia as $numero => $dados) {
                $item = ItemPca::where('plano_contratacao_id', $plano->id)->where('numero', $numero)->first() ?? new ItemPca(['orgao_id' => $plano->orgao_id, 'plano_contratacao_id' => $plano->id]);
                $item->fill(array_diff_key($dados, ['valor_total_estimado' => true]))->save();
            }

            $plano->forceFill([
                'situacao' => 'aprovado', 'aprovado_por' => $vigente->aprovado_por, 'aprovado_em' => $vigente->aprovado_em,
                'revisao_justificativa' => null, 'revisao_aberta_por' => null, 'revisao_aberta_em' => null,
            ])->save();
            Auditoria::registrar('revisao', $plano, "Revisão do PCA {$plano->ano} descartada; itens voltaram à versão {$vigente->numero}");
        });
    }
}
