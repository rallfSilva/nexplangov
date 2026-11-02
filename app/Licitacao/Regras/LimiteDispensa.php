<?php

namespace App\Licitacao\Regras;

use App\Licitacao\Enums\AmparoLegal;
use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Enums\SituacaoProcesso;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\ValorLimite;
use RuntimeException;

/**
 * Dispensa por valor (art. 75, I e II, da Lei 14.133) com controle de fracionamento (art. 75, § 1º):
 * soma o que o órgão já contratou por dispensa no mesmo exercício para objetos da mesma natureza.
 * Consórcio público e agência executiva usam o limite em dobro (art. 75, § 2º).
 */
final class LimiteDispensa
{
    /**
     * @return array{limite: float, ja_contratado: float, total: float, disponivel: float, permitido: bool, ato: string, conferido: bool, dispositivo: string, processos: list<array{numero: string, valor: float}>}
     */
    public function verificar(Orgao $orgao, int $ano, AmparoLegal $amparo, float $valor, ?string $natureza, ?int $ignorarProcessoId = null): array
    {
        if (! $amparo->ehDispensaPorValor()) {
            throw new RuntimeException('O controle de limite só se aplica à dispensa por valor (art. 75, I e II).');
        }

        $dispositivo = $amparo === AmparoLegal::Art75I ? 'art75_I' : 'art75_II';
        $registro = ValorLimite::where('ano', $ano)->where('dispositivo', $dispositivo)->first();

        if (! $registro) {
            throw new RuntimeException("Valor do {$this->rotulo($dispositivo)} para {$ano} não cadastrado. Cadastre o decreto de atualização (art. 182) em Configurações.");
        }

        $limite = (float) $registro->valor * ($orgao->limite_dispensa_em_dobro ? 2 : 1);

        $anteriores = Processo::withoutGlobalScopes()
            ->where('orgao_id', $orgao->id)
            ->where('ano', $ano)
            ->where('modalidade', Modalidade::Dispensa->value)
            ->where('amparo_legal', $amparo->value)
            ->whereNotIn('situacao', [SituacaoProcesso::Revogado->value, SituacaoProcesso::Anulado->value, SituacaoProcesso::Fracassado->value, SituacaoProcesso::Deserto->value])
            ->when($natureza, fn ($q) => $q->where('natureza_despesa', $natureza))
            ->when($ignorarProcessoId, fn ($q) => $q->whereKeyNot($ignorarProcessoId))
            ->get(['id', 'numero', 'ano', 'valor_estimado', 'valor_homologado']);

        $jaContratado = (float) $anteriores->sum(fn (Processo $p) => (float) ($p->valor_homologado ?? $p->valor_estimado));
        $total = round($jaContratado + $valor, 2);

        return [
            'limite' => round($limite, 2),
            'ja_contratado' => round($jaContratado, 2),
            'total' => $total,
            'disponivel' => round(max(0, $limite - $jaContratado), 2),
            'permitido' => $total <= round($limite, 2),
            'ato' => $registro->ato_normativo,
            'conferido' => (bool) $registro->conferido,
            'dispositivo' => $this->rotulo($dispositivo),
            'processos' => $anteriores->map(fn (Processo $p) => ['numero' => "{$p->numero}/{$p->ano}", 'valor' => (float) ($p->valor_homologado ?? $p->valor_estimado)])->values()->all(),
        ];
    }

    private function rotulo(string $dispositivo): string
    {
        return $dispositivo === 'art75_I' ? 'art. 75, I' : 'art. 75, II';
    }
}
