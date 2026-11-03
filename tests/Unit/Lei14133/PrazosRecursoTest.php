<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\DiasUteis;
use App\Licitacao\Regras\PrazosRecurso;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Prazos da fase recursal em dias úteis (art. 165). */
class PrazosRecursoTest extends TestCase
{
    private function prazos(array $feriados = []): PrazosRecurso
    {
        return new PrazosRecurso(new DiasUteis($feriados));
    }

    public function test_razoes_em_tres_dias_uteis_da_ata(): void
    {
        // Ata na sexta 06/11/2026: segunda 09, terça 10, quarta 11.
        $this->assertSame('2026-11-11', $this->prazos()->razoes(CarbonImmutable::parse('2026-11-06 16:00'))->toDateString());
    }

    public function test_feriado_nacional_e_local_nao_contam(): void
    {
        // Ata na quarta 18/11: quinta 19, sexta 20 (Consciência Negra) não conta, segunda 23, terça 24.
        $this->assertSame('2026-11-24', $this->prazos()->razoes(CarbonImmutable::parse('2026-11-18'))->toDateString());
        $this->assertSame('2026-11-25', $this->prazos(['2026-11-19'])->razoes(CarbonImmutable::parse('2026-11-18'))->toDateString());
    }

    public function test_decisao_superior_em_dez_dias_uteis(): void
    {
        $this->assertSame('2026-12-01', $this->prazos()->decisao(CarbonImmutable::parse('2026-11-16'))->toDateString());
    }

    public function test_tempestivo_ate_o_fim_do_ultimo_dia(): void
    {
        $p = $this->prazos();
        $this->assertTrue($p->tempestivo(CarbonImmutable::parse('2026-11-11 23:59'), CarbonImmutable::parse('2026-11-11')));
        $this->assertFalse($p->tempestivo(CarbonImmutable::parse('2026-11-12 00:01'), CarbonImmutable::parse('2026-11-11')));
    }
}
