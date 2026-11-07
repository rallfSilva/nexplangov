<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\NotaEmpenho as N;
use PHPUnit\Framework\TestCase;

/** Nota de empenho e liquidação contra o empenho (Lei 4.320, arts. 58 a 63; Lei 14.133, art. 92, VIII, e art. 141). */
class NotaEmpenhoTest extends TestCase
{
    private function empenho(array $extra = []): array
    {
        return $extra + ['fornecedor_id' => 7, 'contrato_id' => 3, 'ata_id' => null, 'fonte_recurso' => '1500 · Recursos livres', 'exercicio' => 2026, 'anulado' => false, 'saldo' => 1000.0];
    }

    public function test_cadastro_do_empenho(): void
    {
        $ok = ['numero' => '2026NE000123', 'tipo' => 'global', 'valor' => 5000.0, 'emitido_em' => '2026-10-01', 'exercicio' => 2026, 'natureza_despesa' => '3.3.90.39', 'fonte_recurso' => '1500'];
        $this->assertSame([], N::motivosCadastro($ok, '2026-11-03', 5000.0));
        $this->assertSame([], N::motivosCadastro(['natureza_despesa' => '3.3.90.39.99'] + $ok, '2026-11-03'));
        $this->assertCount(1, N::motivosCadastro(['emitido_em' => '2026-12-01'] + $ok, '2026-11-03'));
        $this->assertStringContainsString('art. 35', N::motivosCadastro(['exercicio' => 2025] + $ok, '2026-11-03')[0]);
        $this->assertStringContainsString('art. 125', N::motivosCadastro($ok, '2026-11-03', 4999.99)[0]);
        $this->assertCount(3, N::motivosCadastro(['numero' => 'x', 'tipo' => 'outro', 'natureza_despesa' => '339039'] + $ok, '2026-11-03'));
        $this->assertSame(250.0, N::saldo(1000, 250, 500));
    }

    public function test_liquidacao_contra_o_empenho(): void
    {
        $this->assertSame([], N::motivosLiquidacao($this->empenho(), 7, 3, ' 1500 · recursos livres ', 1000.0, 2026));
        $this->assertSame([], N::motivosLiquidacao($this->empenho(['exercicio' => 2025]), 7, 3, '1500 · Recursos livres', 10.0, 2026), 'restos a pagar de exercício anterior');
        $this->assertStringContainsString('outro credor', N::motivosLiquidacao($this->empenho(), 8, 3, '1500 · Recursos livres', 10.0, 2026)[0]);
        $this->assertStringContainsString('outro contrato', N::motivosLiquidacao($this->empenho(), 7, 4, '1500 · Recursos livres', 10.0, 2026)[0]);
        $this->assertStringContainsString('outra ata', N::motivosLiquidacao($this->empenho(['contrato_id' => null, 'ata_id' => 5]), 7, null, '1500 · Recursos livres', 10.0, 2026, 6)[0]);
        $this->assertStringContainsString('art. 141', N::motivosLiquidacao($this->empenho(), 7, 3, '1700 · Saúde', 10.0, 2026)[0]);
        $this->assertStringContainsString('saldo do empenho', N::motivosLiquidacao($this->empenho(), 7, 3, '1500 · Recursos livres', 1000.01, 2026)[0]);
        $this->assertStringContainsString('anulado', N::motivosLiquidacao($this->empenho(['anulado' => true]), 7, 3, '1500 · Recursos livres', 10.0, 2026)[0]);
        $this->assertNotEmpty(N::motivosLiquidacao($this->empenho(['exercicio' => 2027]), 7, 3, '1500 · Recursos livres', 10.0, 2026));
    }
}
