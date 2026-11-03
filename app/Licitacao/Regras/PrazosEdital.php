<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Impugnação e pedido de esclarecimento (art. 164): protocolo até 3 dias úteis antes da abertura;
 * resposta em até 3 dias úteis, limitada ao último dia útil anterior à abertura.
 */
final class PrazosEdital
{
    public const DIAS_UTEIS = 3;

    public function __construct(private DiasUteis $dias) {}

    /** Último dia em que o pedido pode ser protocolado. */
    public function limiteProtocolo(CarbonInterface $abertura): CarbonImmutable
    {
        return $this->dias->subtrair($abertura, self::DIAS_UTEIS);
    }

    public function tempestiva(CarbonInterface $protocolo, CarbonInterface $abertura): bool
    {
        return $protocolo->toDateString() <= $this->limiteProtocolo($abertura)->toDateString();
    }

    /** Data limite para divulgar a resposta (art. 164, parágrafo único). */
    public function prazoResposta(CarbonInterface $recebida, CarbonInterface $abertura): CarbonImmutable
    {
        $tres = $this->dias->somar($recebida, self::DIAS_UTEIS);
        $teto = $this->dias->ultimoUtilAntes($abertura);

        return $tres->lessThan($teto) ? $tres : $teto;
    }
}
