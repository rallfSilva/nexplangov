<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\PesquisaPrecos;
use PHPUnit\Framework\TestCase;

class PesquisaPrecosTest extends TestCase
{
    private function c(float $valor, string $fonte = 'painel_oficial', string $data = '2026-10-01'): array
    {
        return ['valor' => $valor, 'fonte' => $fonte, 'data' => $data];
    }

    public function test_mediana_com_numero_par_e_impar(): void
    {
        $this->assertSame(20.0, PesquisaPrecos::mediana([10, 30, 20]));
        $this->assertSame(25.0, PesquisaPrecos::mediana([10, 20, 30, 40]));
    }

    public function test_calcula_mediana_e_media(): void
    {
        $p = new PesquisaPrecos;
        $cotacoes = [$this->c(100), $this->c(110), $this->c(120)];

        $this->assertSame(110.0, $p->calcular($cotacoes, 'mediana', '2026-11-02')['valor_unitario']);
        $this->assertSame(110.0, $p->calcular($cotacoes, 'media', '2026-11-02')['valor_unitario']);
        $this->assertSame(100.0, $p->calcular($cotacoes, 'menor', '2026-11-02')['valor_unitario']);
    }

    public function test_descarta_orcamento_de_fornecedor_com_mais_de_6_meses(): void
    {
        $r = (new PesquisaPrecos)->calcular([
            $this->c(100, 'fornecedor', '2026-03-01'),
            $this->c(105, 'fornecedor', '2026-10-01'),
            $this->c(110, 'fornecedor', '2026-10-15'),
            $this->c(108, 'fornecedor', '2026-10-20'),
        ], 'mediana', '2026-11-02');

        $this->assertArrayHasKey(0, $r['descartadas']);
        $this->assertStringContainsString('6 meses', $r['descartadas'][0]);
        $this->assertSame(108.0, $r['valor_unitario']);
    }

    public function test_descarta_contratacao_similar_com_mais_de_1_ano(): void
    {
        $r = (new PesquisaPrecos)->calcular([
            $this->c(50, 'contratacoes_similares', '2025-09-30'),
            $this->c(52, 'contratacoes_similares', '2026-01-10'),
        ], 'mediana', '2026-11-02');

        $this->assertArrayHasKey(0, $r['descartadas']);
        $this->assertSame([1], $r['validas']);
    }

    public function test_marca_preco_destoante_acima_e_abaixo_da_mediana(): void
    {
        $r = (new PesquisaPrecos)->calcular([$this->c(100), $this->c(105), $this->c(95), $this->c(400), $this->c(20)], 'media', '2026-11-02');

        $this->assertStringContainsString('Excessivamente elevada', $r['descartadas'][3]);
        $this->assertStringContainsString('inexequível', $r['descartadas'][4]);
        $this->assertSame(100.0, $r['valor_unitario']);
    }

    public function test_alerta_quando_ha_menos_de_tres_precos(): void
    {
        $r = (new PesquisaPrecos)->calcular([$this->c(10), $this->c(12)], 'mediana', '2026-11-02');

        $this->assertNotEmpty($r['alertas']);
        $this->assertSame(11.0, $r['valor_unitario']);
    }

    public function test_respeita_desconsideracao_manual(): void
    {
        $r = (new PesquisaPrecos)->calcular([
            $this->c(10), $this->c(11), ['valor' => 12, 'fonte' => 'painel_oficial', 'data' => '2026-10-01', 'desconsiderada' => true, 'motivo' => 'Produto diferente'],
        ], 'mediana', '2026-11-02');

        $this->assertSame('Produto diferente', $r['descartadas'][2]);
    }

    public function test_coeficiente_de_variacao(): void
    {
        $this->assertNull(PesquisaPrecos::coeficienteVariacao([10]));
        $this->assertSame(10.0, PesquisaPrecos::coeficienteVariacao([90, 100, 110]));
    }
}
