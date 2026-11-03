<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\RegistroDePrecos;
use Carbon\CarbonImmutable as D;
use PHPUnit\Framework\TestCase;

class RegistroDePrecosTest extends TestCase
{
    public function test_vigencia_inicial_de_no_maximo_um_ano(): void
    {
        $r = new RegistroDePrecos;
        $this->assertSame([], $r->validarVigencia(D::parse('2026-03-01'), D::parse('2027-02-28')));
        $this->assertNotSame([], $r->validarVigencia(D::parse('2026-03-01'), D::parse('2027-03-01')));
        $this->assertNotSame([], $r->validarVigencia(D::parse('2026-03-01'), D::parse('2026-03-01')));
    }

    public function test_prorrogacao_uma_vez_por_igual_periodo_com_vantagem_comprovada(): void
    {
        $r = new RegistroDePrecos;
        $inicio = D::parse('2026-03-01');
        $fim = D::parse('2027-02-28');

        $this->assertSame([], $r->validarProrrogacao($inicio, $fim, D::parse('2028-02-29'), false, 'Preço 8% abaixo da média de mercado.'));
        $this->assertNotSame([], $r->validarProrrogacao($inicio, $fim, D::parse('2028-03-01'), false, 'Preço 8% abaixo da média.'));
        $this->assertNotSame([], $r->validarProrrogacao($inicio, $fim, D::parse('2027-12-31'), true, 'Preço 8% abaixo da média.'));
        $this->assertNotSame([], $r->validarProrrogacao($inicio, $fim, D::parse('2027-12-31'), false, 'curto'));
        $this->assertNotSame([], $r->validarProrrogacao($inicio, $fim, D::parse('2027-02-01'), false, 'Preço 8% abaixo da média.'));
    }

    public function test_adesao_ate_50_por_cento_por_aderente(): void
    {
        $r = new RegistroDePrecos;
        $this->assertTrue($r->avaliarAdesao(1000, 0, 0, 500)['permitido']);

        $x = $r->avaliarAdesao(1000, 0, 0, 500.01);
        $this->assertFalse($x['permitido']);
        $this->assertStringContainsString('art. 86, § 4º', $x['mensagens'][0]);
        $this->assertFalse($r->avaliarAdesao(1000, 400, 400, 101)['permitido']);
    }

    public function test_adesoes_somadas_ate_o_dobro_do_registrado(): void
    {
        $r = new RegistroDePrecos;
        $this->assertTrue($r->avaliarAdesao(1000, 1500, 0, 500)['permitido']);

        $x = $r->avaliarAdesao(1000, 1600, 0, 500);
        $this->assertFalse($x['permitido']);
        $this->assertStringContainsString('art. 86, § 5º', $x['mensagens'][0]);
        $this->assertSame(2000.0, $x['limite_total']);
    }

    public function test_quantidade_zero_ou_negativa_e_recusada(): void
    {
        $this->assertFalse((new RegistroDePrecos)->avaliarAdesao(1000, 0, 0, 0)['permitido']);
    }
}
