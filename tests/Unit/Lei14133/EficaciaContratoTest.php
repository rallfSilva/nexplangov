<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\DiasUteis;
use App\Licitacao\Regras\EficaciaContrato;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Divulgação no PNCP como condição de eficácia do contrato (art. 94). */
class EficaciaContratoTest extends TestCase
{
    private function regra(): EficaciaContrato
    {
        return new EficaciaContrato(new DiasUteis);
    }

    public function test_prazo_e_de_20_dias_uteis_na_licitacao_e_10_na_contratacao_direta(): void
    {
        $assinatura = CarbonImmutable::parse('2026-03-02'); // segunda-feira, sem feriado no período

        $this->assertSame('2026-03-30', $this->regra()->limite($assinatura, false)->toDateString());
        $this->assertSame('2026-03-16', $this->regra()->limite($assinatura, true)->toDateString());
    }

    public function test_feriado_nacional_nao_conta(): void
    {
        // 21/04 (Tiradentes) cai no meio do prazo e empurra o limite em um dia útil.
        $this->assertSame('2026-04-29', $this->regra()->limite(CarbonImmutable::parse('2026-04-14'), true)->toDateString());
    }

    public function test_situacao_no_prazo_vencido_e_publicado(): void
    {
        $assinatura = CarbonImmutable::parse('2026-03-02');

        $noPrazo = $this->regra()->situacao($assinatura, true, null, CarbonImmutable::parse('2026-03-12'));
        $this->assertSame('no_prazo', $noPrazo['estado']);
        $this->assertSame(2, $noPrazo['restantes']); // 13 e 16 de março
        $this->assertSame('art. 94, II', $noPrazo['base']);

        $this->assertSame('vencido', $this->regra()->situacao($assinatura, true, null, CarbonImmutable::parse('2026-03-17'))['estado']);
        $this->assertSame('publicado', $this->regra()->situacao($assinatura, false, CarbonImmutable::parse('2026-03-30 18:00'), CarbonImmutable::parse('2026-05-01'))['estado']);
        $this->assertSame('publicado_fora_do_prazo', $this->regra()->situacao($assinatura, true, CarbonImmutable::parse('2026-03-17'), CarbonImmutable::parse('2026-05-01'))['estado']);
    }

    public function test_sem_publicacao_nao_se_executa(): void
    {
        $this->assertSame([], $this->regra()->motivosBloqueio(true, 'pagar'));
        $this->assertStringContainsString('art. 94', $this->regra()->motivosBloqueio(false, 'pagar')[0]);
    }
}
