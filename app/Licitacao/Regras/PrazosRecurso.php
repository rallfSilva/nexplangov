<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Prazos da fase recursal, todos em dias úteis:
 * - razões: 3 dias úteis da lavratura da ata da sessão (art. 165, I, e § 1º, I);
 * - contrarrazões: 3 dias úteis da divulgação da interposição (art. 165, § 4º);
 * - reconsideração pelo agente: 3 dias úteis depois das contrarrazões (art. 165, § 2º);
 * - decisão da autoridade superior: 10 dias úteis do recebimento dos autos (art. 165, § 2º).
 */
final class PrazosRecurso
{
    public const RAZOES = 3;

    public const CONTRARRAZOES = 3;

    public const RECONSIDERACAO = 3;

    public const DECISAO = 10;

    public function __construct(private DiasUteis $dias) {}

    public function razoes(CarbonInterface $ataLavrada): CarbonImmutable
    {
        return $this->dias->somar($ataLavrada, self::RAZOES);
    }

    public function contrarrazoes(CarbonInterface $interposicao): CarbonImmutable
    {
        return $this->dias->somar($interposicao, self::CONTRARRAZOES);
    }

    public function reconsideracao(CarbonInterface $fimContrarrazoes): CarbonImmutable
    {
        return $this->dias->somar($fimContrarrazoes, self::RECONSIDERACAO);
    }

    public function decisao(CarbonInterface $encaminhamento): CarbonImmutable
    {
        return $this->dias->somar($encaminhamento, self::DECISAO);
    }

    /** Tempestivo quando apresentado até o fim do dia do prazo. */
    public function tempestivo(CarbonInterface $quando, CarbonInterface $prazo): bool
    {
        return $quando->toDateString() <= $prazo->toDateString();
    }
}
