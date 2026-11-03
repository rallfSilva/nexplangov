<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\DiasUteis;
use App\Licitacao\Regras\Sancoes;
use Carbon\CarbonImmutable as D;
use PHPUnit\Framework\TestCase;

/** Processo sancionador (arts. 155 a 163, 166 e 167). */
class SancoesTest extends TestCase
{
    private function r(): Sancoes
    {
        return new Sancoes(new DiasUteis);
    }

    public function test_advertencia_so_para_o_inciso_i(): void
    {
        $this->assertSame([], $this->r()->validarSancao(['I'], 'advertencia'));
        $this->assertStringContainsString('§ 2º', $this->r()->validarSancao(['I', 'III'], 'advertencia')[0]);
    }

    public function test_impedimento_para_ii_a_vii_e_inidoneidade_para_viii_a_xii(): void
    {
        $r = $this->r();
        $this->assertSame([], $r->validarSancao(['III', 'VII'], 'impedimento'));
        $this->assertNotSame([], $r->validarSancao(['IX'], 'impedimento'));
        $this->assertSame([], $r->validarSancao(['IX'], 'inidoneidade'));
        $this->assertStringContainsString('§ 5º', $r->validarSancao(['III'], 'inidoneidade')[0]);
        $this->assertSame([], $r->validarSancao(['III'], 'inidoneidade', 'Inexecução total em contrato de merenda escolar, com dano grave aos alunos.'));
        $this->assertSame([], $r->validarSancao(['I', 'IX'], 'multa'));
        $this->assertNotSame([], $r->validarSancao([], 'multa'));
        $this->assertNotSame([], $r->validarSancao(['XIII'], 'multa'));
    }

    public function test_multa_entre_meio_e_trinta_por_cento(): void
    {
        $r = $this->r();
        $this->assertSame([], $r->validarMulta(500, 100000));
        $this->assertSame([], $r->validarMulta(30000, 100000));
        $this->assertStringContainsString('§ 3º', $r->validarMulta(499.99, 100000)[0]);
        $this->assertNotSame([], $r->validarMulta(30000.01, 100000));
        $this->assertNotSame([], $r->validarMulta(10, 0));
    }

    public function test_duracao_do_impedimento_e_da_inidoneidade(): void
    {
        $r = $this->r();
        $this->assertSame([], $r->validarDuracao('impedimento', 36));
        $this->assertNotSame([], $r->validarDuracao('impedimento', 37));
        $this->assertNotSame([], $r->validarDuracao('inidoneidade', 35));
        $this->assertSame([], $r->validarDuracao('inidoneidade', 72));
        $this->assertSame([], $r->validarDuracao('multa', null));
        $this->assertSame('2029-11-08', $r->fimDosEfeitos(D::parse('2026-11-09'), 36)->toDateString());
    }

    public function test_prazos_em_dias_uteis(): void
    {
        $r = $this->r();
        // Intimação na segunda 09/11/2026; 20/11 é feriado nacional (Consciência Negra).
        $this->assertSame('2026-12-01', $r->prazoDefesa(D::parse('2026-11-09'))->toDateString());
        $this->assertSame('2026-12-01', $r->prazoRecurso(D::parse('2026-11-09'))->toDateString());
        $this->assertSame('2026-11-16', $r->prazoReconsideracao(D::parse('2026-11-09'))->toDateString());
        $this->assertSame('reconsideracao', $r->meioDeImpugnacao('inidoneidade'));
        $this->assertSame('recurso', $r->meioDeImpugnacao('multa'));
    }

    public function test_reabilitacao_exige_prazo_minimo_e_requisitos(): void
    {
        $r = $this->r();
        $ok = ['reparacao' => true, 'multa_paga' => true, 'condicoes' => true, 'integridade' => false, 'parecer' => true];
        $this->assertSame([], $r->validarReabilitacao('impedimento', D::parse('2025-11-01'), D::parse('2026-11-03'), ['III'], $ok));
        $this->assertStringContainsString('art. 163, III', $r->validarReabilitacao('impedimento', D::parse('2026-01-01'), D::parse('2026-11-03'), ['III'], $ok)[0]);
        $this->assertStringContainsString('integridade', implode(' ', $r->validarReabilitacao('inidoneidade', D::parse('2022-01-01'), D::parse('2026-11-03'), ['VIII'], $ok)));
        $this->assertCount(1, $r->validarReabilitacao('advertencia', D::parse('2020-01-01'), D::parse('2026-11-03'), ['I'], $ok));
    }
}
