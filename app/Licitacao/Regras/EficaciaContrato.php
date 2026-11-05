<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Eficácia do contrato sob condição suspensiva (art. 94 da Lei 14.133): a divulgação no PNCP é condição
 * indispensável para a eficácia do contrato, em até 20 dias úteis da assinatura na licitação (inciso I) e
 * 10 dias úteis na contratação direta (inciso II). Antes da divulgação, o contrato não produz efeitos:
 * não se mede, não se liquida e não se paga.
 */
final class EficaciaContrato
{
    public const DIAS_LICITACAO = 20;

    public const DIAS_DIRETA = 10;

    /** Faltando até esta quantidade de dias úteis, o alerta vira urgente. */
    public const DIAS_ALERTA = 3;

    public function __construct(private DiasUteis $dias) {}

    public function prazoDias(bool $direta): int
    {
        return $direta ? self::DIAS_DIRETA : self::DIAS_LICITACAO;
    }

    public function limite(CarbonInterface $assinatura, bool $direta): CarbonImmutable
    {
        return $this->dias->somar($assinatura, $this->prazoDias($direta));
    }

    /**
     * Estado da divulgação: publicado, publicado_fora_do_prazo, no_prazo ou vencido.
     *
     * @return array{estado: string, limite: CarbonImmutable, restantes: ?int, base: string}
     */
    public function situacao(CarbonInterface $assinatura, bool $direta, ?CarbonInterface $publicadoEm, CarbonInterface $hoje): array
    {
        $limite = $this->limite($assinatura, $direta);
        $base = $direta ? 'art. 94, II' : 'art. 94, I';

        if ($publicadoEm !== null) {
            return ['estado' => CarbonImmutable::parse($publicadoEm)->startOfDay()->greaterThan($limite) ? 'publicado_fora_do_prazo' : 'publicado', 'limite' => $limite, 'restantes' => null, 'base' => $base];
        }

        $hoje = CarbonImmutable::parse($hoje)->startOfDay();

        if ($hoje->greaterThan($limite)) {
            return ['estado' => 'vencido', 'limite' => $limite, 'restantes' => 0, 'base' => $base];
        }

        return ['estado' => 'no_prazo', 'limite' => $limite, 'restantes' => $this->diasUteisAte($hoje, $limite), 'base' => $base];
    }

    /**
     * Motivos que impedem executar o contrato (medir, liquidar, pagar). Lista vazia: pode executar.
     *
     * @return list<string>
     */
    public function motivosBloqueio(bool $publicado, string $acao): array
    {
        if ($publicado) {
            return [];
        }

        return ["O contrato ainda não foi divulgado no PNCP e por isso não tem eficácia: não é possível {$acao}. Publique o contrato no PNCP primeiro (art. 94)."];
    }

    /** Dias úteis de hoje (exclusive) até o limite (inclusive). */
    private function diasUteisAte(CarbonImmutable $hoje, CarbonImmutable $limite): int
    {
        $n = 0;
        for ($d = $hoje->addDay(); $d->lessThanOrEqualTo($limite); $d = $d->addDay()) {
            if ($this->dias->ehUtil($d)) {
                $n++;
            }
        }

        return $n;
    }
}
