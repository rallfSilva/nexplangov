<?php

namespace App\Licitacao\Pca;

use App\Licitacao\Regras\ConsolidacaoDfd;
use App\Licitacao\Regras\UnidadesPncpPca;
use App\Models\Demanda;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Unidade;

/**
 * Resumo do PCA para a lista de planos e as abas do plano: valores, itens, DFDs e situação no PNCP,
 * no total e por unidade participante (art. 12, VII, e § 1º).
 */
final class PainelPca
{
    /** Situações do DFD que contam como aprovado: aprovado, consolidado no plano ou que já virou processo. */
    public const APROVADOS = ['aceita', 'consolidada', 'atendida'];

    /**
     * @return array{valor: float, itens: int, dfds_aprovados: int, dfds_total: int, pncp: string,
     *     unidades: list<array{unidade: Unidade, valor: float, itens: int, pncp: string, consolidados: int, total: int, percentual: int}>}
     */
    public static function resumo(PlanoContratacao $plano): array
    {
        $unidades = Unidade::where('orgao_id', $plano->orgao_id)->get(['id', 'parent_id', 'sigla', 'nome', 'codigo_unidade_pncp'])->keyBy('id');
        $pais = $unidades->map(fn ($u) => $u->parent_id)->all();
        $codigos = $unidades->map(fn ($u) => ['parent' => $u->parent_id, 'codigo' => $u->codigo_unidade_pncp])->all();
        $participantes = $plano->idsParticipantes();
        $linhas = $plano->unidadesDoPlano()->mapWithKeys(fn (Unidade $u) => [$u->id => ['unidade' => $u, 'valor' => 0.0, 'itens' => 0, 'pncp' => 'pendente', 'consolidados' => 0, 'total' => 0, 'percentual' => 0]])->all();
        $de = fn (?int $u) => $u === null ? null : ConsolidacaoDfd::participanteDe($pais, $participantes, $u);

        $itens = ItemPca::where('plano_contratacao_id', $plano->id)->get(['unidade_id', 'valor_total_estimado']);
        foreach ($itens as $i) {
            if (($p = $de($i->unidade_id)) !== null && isset($linhas[$p])) {
                $linhas[$p]['valor'] = round($linhas[$p]['valor'] + (float) $i->valor_total_estimado, 2);
                $linhas[$p]['itens']++;
            }
        }

        $dfds = Demanda::where('exercicio', $plano->ano)->whereNotIn('situacao', ['rascunho', 'recusada'])->get(['unidade_id', 'situacao', 'consolidada_em']);
        foreach ($dfds as $d) {
            if (($p = $de($d->unidade_id)) !== null && isset($linhas[$p])) {
                $linhas[$p]['total']++;
                $linhas[$p]['consolidados'] += $d->consolidada_em ? 1 : 0;
            }
        }

        $publicadas = $plano->pcasUnidade()->whereNotNull('numero_controle_pncp')->pluck('unidade_id')->map(fn ($i) => (int) $i)->all();
        foreach ($linhas as $id => &$l) {
            $l['percentual'] = $l['total'] ? (int) floor(100 * $l['consolidados'] / $l['total']) : 0;
            $publicadora = UnidadesPncpPca::publicadora($codigos, $id);
            $l['pncp'] = $publicadora !== null && in_array($publicadora, $publicadas, true) ? 'publicado' : 'pendente';
        }
        unset($l);

        $grupos = array_keys($plano->gruposPncp()['grupos']);
        $pncp = match (true) {
            $grupos !== [] && array_diff($grupos, $publicadas) === [] => 'publicado',
            $publicadas !== [] => 'parcial',
            default => 'nao',
        };

        return [
            'valor' => round((float) $itens->sum('valor_total_estimado'), 2),
            'itens' => $itens->count(),
            'dfds_aprovados' => $dfds->whereIn('situacao', self::APROVADOS)->count(),
            'dfds_total' => $dfds->count(),
            'pncp' => $pncp,
            'unidades' => array_values($linhas),
        ];
    }
}
