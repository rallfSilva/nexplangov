<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Regras da ata de registro de preços: vigência de 1 ano, prorrogável por igual período se o preço
 * continuar vantajoso (art. 84), e limites da adesão de órgão não participante, o "carona":
 * até 50% do quantitativo registrado por aderente e, no total, até o dobro do registrado (art. 86, §§ 2º a 5º).
 */
final class RegistroDePrecos
{
    public const PERCENTUAL_POR_ADERENTE = 0.50;

    public const MULTIPLO_TOTAL_ADESOES = 2.0;

    /** Fim máximo da vigência inicial: 1 ano a contar do início, contado como o dia anterior ao aniversário. */
    public function fimMaximoInicial(CarbonInterface $inicio): CarbonImmutable
    {
        return CarbonImmutable::parse($inicio->toDateString())->addYear()->subDay();
    }

    /** Com a prorrogação, o limite é de 2 anos no total. */
    public function fimMaximoProrrogado(CarbonInterface $inicio): CarbonImmutable
    {
        return CarbonImmutable::parse($inicio->toDateString())->addYears(2)->subDay();
    }

    /** @return list<string> motivos que impedem a vigência informada */
    public function validarVigencia(CarbonInterface $inicio, CarbonInterface $fim): array
    {
        if ($fim->lte($inicio)) {
            return ['O fim da vigência precisa ser depois do início.'];
        }

        return $fim->toDateString() > $this->fimMaximoInicial($inicio)->toDateString()
            ? ['A ata vale por 1 ano. Prorrogue por igual período só depois, comprovando a vantagem do preço (art. 84).']
            : [];
    }

    /** @return list<string> */
    public function validarProrrogacao(CarbonInterface $inicio, CarbonInterface $fimAtual, CarbonInterface $novoFim, bool $jaProrrogada, ?string $vantagem): array
    {
        $m = [];

        if ($jaProrrogada) {
            $m[] = 'A ata já foi prorrogada. A lei permite uma prorrogação, por igual período (art. 84).';
        }
        if ($novoFim->lte($fimAtual)) {
            $m[] = 'O novo fim precisa ser depois da vigência atual.';
        }
        if ($novoFim->toDateString() > $this->fimMaximoProrrogado($inicio)->toDateString()) {
            $m[] = 'A vigência total da ata não pode passar de 2 anos (1 ano mais a prorrogação, art. 84).';
        }
        if (mb_strlen(trim((string) $vantagem)) < 10) {
            $m[] = 'Comprove que o preço continua vantajoso para prorrogar (art. 84).';
        }

        return $m;
    }

    /**
     * Avalia a adesão de um órgão não participante a um item da ata.
     *
     * @param  float  $registrada  quantidade registrada do item
     * @param  float  $jaAutorizadoTotal  soma das adesões já autorizadas no item, de todos os aderentes
     * @param  float  $jaAutorizadoAderente  o que este aderente já tem autorizado no item
     * @return array{permitido: bool, limite_por_aderente: float, limite_total: float, mensagens: list<string>}
     */
    public function avaliarAdesao(float $registrada, float $jaAutorizadoTotal, float $jaAutorizadoAderente, float $solicitada): array
    {
        $limiteAderente = round($registrada * self::PERCENTUAL_POR_ADERENTE, 4);
        $limiteTotal = round($registrada * self::MULTIPLO_TOTAL_ADESOES, 4);
        $m = [];

        if ($solicitada <= 0) {
            $m[] = 'A quantidade precisa ser maior que zero.';
        }
        if (round($jaAutorizadoAderente + $solicitada, 4) > $limiteAderente) {
            $m[] = sprintf('Cada aderente pode contratar até 50%% do registrado (%s). Com este pedido chegaria a %s (art. 86, § 4º).', $this->n($limiteAderente), $this->n($jaAutorizadoAderente + $solicitada));
        }
        if (round($jaAutorizadoTotal + $solicitada, 4) > $limiteTotal) {
            $m[] = sprintf('As adesões somadas não podem passar do dobro do registrado (%s). Com este pedido chegariam a %s (art. 86, § 5º).', $this->n($limiteTotal), $this->n($jaAutorizadoTotal + $solicitada));
        }

        return ['permitido' => $m === [], 'limite_por_aderente' => $limiteAderente, 'limite_total' => $limiteTotal, 'mensagens' => $m];
    }

    /**
     * Órgão federal não pode aderir a ata de gerenciador estadual, distrital ou municipal (art. 86, § 8º).
     * O § 3º, com a redação da Lei nº 14.770/2023, admite adesão a ata de gerenciador de qualquer esfera.
     *
     * @return list<string>
     */
    public function vedacaoPorEsfera(string $esferaGerenciador, string $esferaAderente): array
    {
        if ($esferaAderente === 'federal' && $esferaGerenciador !== 'federal') {
            return ['Órgão ou entidade da Administração Pública federal não pode aderir a ata gerenciada por órgão estadual, distrital ou municipal (art. 86, § 8º).'];
        }

        return [];
    }

    /**
     * Prazo para o aderente contratar depois da autorização: o fixado no regulamento, nunca além da vigência da ata.
     */
    public function prazoContratacao(CarbonInterface $autorizacao, CarbonInterface $fimVigencia, int $dias): CarbonImmutable
    {
        $prazo = CarbonImmutable::parse($autorizacao->toDateString())->addDays($dias);
        $fim = CarbonImmutable::parse($fimVigencia->toDateString());

        return $prazo->gt($fim) ? $fim : $prazo;
    }

    private function n(float $v): string
    {
        return number_format($v, 4, ',', '.');
    }
}
