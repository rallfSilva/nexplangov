<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\OrdemCronologica;
use Carbon\CarbonImmutable as D;
use PHPUnit\Framework\TestCase;

/** Ordem cronológica de pagamentos (art. 141). */
class OrdemCronologicaTest extends TestCase
{
    public function test_categoria_sugerida_pela_categoria_do_processo(): void
    {
        $r = new OrdemCronologica;
        $this->assertSame('fornecimento', $r->categoriaSugerida(2));
        $this->assertSame('locacao', $r->categoriaSugerida(5));
        $this->assertSame('obras', $r->categoriaSugerida(7));
        $this->assertSame('servicos', $r->categoriaSugerida(8));
        $this->assertSame('servicos', $r->categoriaSugerida(null));
    }

    public function test_pagamento_fora_da_ordem_so_com_justificativa(): void
    {
        $r = new OrdemCronologica;
        $this->assertSame([], $r->validarPagamento([], false));
        $this->assertStringContainsString('art. 141, § 1º', $r->validarPagamento(['L-1'], false)[0]);
        $this->assertSame([], $r->validarPagamento(['L-1'], true));
    }

    public function test_justificativa_exige_hipotese_legal_e_fundamento(): void
    {
        $r = new OrdemCronologica;
        $this->assertCount(2, $r->validarJustificativa('VI', 'curto'));
        $this->assertSame([], $r->validarJustificativa('II', 'Microempresa fornecedora de merenda com risco de parar a entrega às escolas.'));
    }

    public function test_vencimento_e_atraso_que_permite_extincao(): void
    {
        $r = new OrdemCronologica;
        $this->assertSame('2026-12-03', $r->vencimento(D::parse('2026-11-03'), 30)->toDateString());
        $this->assertFalse($r->atrasoPermiteExtincao(D::parse('2026-01-31'), D::parse('2026-03-31')));
        $this->assertTrue($r->atrasoPermiteExtincao(D::parse('2026-01-31'), D::parse('2026-04-01')));
    }
}
