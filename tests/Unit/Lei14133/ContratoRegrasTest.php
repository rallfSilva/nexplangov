<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\AlteracaoContratual;
use App\Licitacao\Regras\Garantia;
use App\Licitacao\Regras\VigenciaContratual;
use PHPUnit\Framework\TestCase;

class ContratoRegrasTest extends TestCase
{
    public function test_acrescimo_ate_25_por_cento_e_permitido(): void
    {
        $r = (new AlteracaoContratual)->avaliar(100000, 10000, 0, 15000, 0);

        $this->assertTrue($r['permitido']);
        $this->assertSame(25.0, $r['percentual_acrescimo']);
    }

    public function test_acrescimo_acima_de_25_por_cento_e_bloqueado(): void
    {
        $r = (new AlteracaoContratual)->avaliar(100000, 20000, 0, 5000.01, 0);

        $this->assertFalse($r['permitido']);
        $this->assertStringContainsString('art. 125', $r['mensagens'][0]);
    }

    public function test_reforma_admite_ate_50_por_cento_de_acrescimo(): void
    {
        $this->assertTrue((new AlteracaoContratual)->avaliar(100000, 0, 0, 50000, 0, reforma: true)['permitido']);
        $this->assertFalse((new AlteracaoContratual)->avaliar(100000, 0, 0, 50001, 0, reforma: true)['permitido']);
    }

    public function test_acrescimo_e_supressao_nao_se_compensam(): void
    {
        $r = (new AlteracaoContratual)->avaliar(100000, 0, 20000, 26000, 0);

        $this->assertFalse($r['permitido']);
    }

    public function test_servico_continuo_ate_5_anos(): void
    {
        $v = new VigenciaContratual;

        $this->assertSame([], $v->validarInicial('2026-01-01', '2030-12-31', true));
        $this->assertNotEmpty($v->validarInicial('2026-01-01', '2031-01-01', true));
        $this->assertSame([], $v->validarInicial('2026-01-01', '2031-01-01', true, 'Art. 108'));
    }

    public function test_prorrogacao_ate_10_anos(): void
    {
        $v = new VigenciaContratual;

        $this->assertSame([], $v->validarProrrogacao('2026-01-01', '2035-12-31', true));
        $this->assertNotEmpty($v->validarProrrogacao('2026-01-01', '2036-01-01', true));
    }

    public function test_fim_antes_do_inicio(): void
    {
        $this->assertNotEmpty((new VigenciaContratual)->validarInicial('2026-05-01', '2026-04-30', false));
    }

    public function test_dias_para_vencer(): void
    {
        $this->assertSame(30, VigenciaContratual::diasParaVencer('2026-12-02', '2026-11-02'));
        $this->assertSame(-1, VigenciaContratual::diasParaVencer('2026-11-01', '2026-11-02'));
    }

    public function test_garantia(): void
    {
        $g = new Garantia;

        $this->assertSame([], $g->validar(5, null));
        $this->assertNotEmpty($g->validar(8, ''));
        $this->assertSame([], $g->validar(8, 'Complexidade técnica alta'));
        $this->assertNotEmpty($g->validar(12, 'x'));
        $this->assertSame([], $g->validar(30, null, grandeVultoEngenharia: true));
    }
}
