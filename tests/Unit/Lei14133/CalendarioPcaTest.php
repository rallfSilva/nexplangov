<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\CalendarioPca as C;
use PHPUnit\Framework\TestCase;

/** Calendário de elaboração do PCA (art. 12, VII, e § 1º). */
class CalendarioPcaTest extends TestCase
{
    private const DATAS = ['inicio_demandas' => '2026-03-01', 'fim_dfds' => '2026-05-31', 'fim_aprovacao' => '2026-06-30',
        'fim_revisao' => '2026-07-31', 'fim_consolidacao' => '2026-08-31', 'fim_divulgacao' => '2026-12-31'];

    public function test_calendario_em_ordem_passa(): void
    {
        $this->assertSame([], C::motivos(self::DATAS, 2027));
    }

    public function test_aponta_data_faltando_fora_de_ordem_e_divulgacao_depois_do_exercicio(): void
    {
        $m = C::motivos(['fim_dfds' => '2026-02-01', 'fim_divulgacao' => '2028-01-10'] + self::DATAS, 2027);
        $this->assertContains('Fim do cadastro dos DFDs não pode ser antes de: início do cadastro das demandas.', $m);
        $this->assertContains('A divulgação do PCA 2027 precisa acontecer até 31/12/2027.', $m);

        $this->assertContains('Informe a data: Fim da revisão.', C::motivos(['fim_revisao' => null] + self::DATAS, 2027));
    }

    public function test_etapa_atual(): void
    {
        $this->assertSame('inicio_demandas', C::etapaAtual(self::DATAS, '2026-01-10')['chave']);
        $this->assertSame('fim_dfds', C::etapaAtual(self::DATAS, '2026-05-31')['chave'], 'o último dia ainda vale');
        $this->assertSame('fim_aprovacao', C::etapaAtual(self::DATAS, '2026-06-01')['chave']);
        $this->assertTrue(C::etapaAtual(self::DATAS, '2027-01-02')['encerrado']);
        $this->assertNull(C::etapaAtual([], '2027-01-02'));
    }
}
