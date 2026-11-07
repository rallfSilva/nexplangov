<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\ConsolidacaoPca;
use App\Licitacao\Regras\CronogramaPca;
use App\Licitacao\Regras\FracionamentoHistorico;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Consolidação do PCA (art. 40, V, e § 2º), cronograma com orçamento (arts. 18 e 150) e compras recorrentes (art. 75, § 1º). */
class ConsolidacaoPcaTest extends TestCase
{
    private function item(int $id, ?string $unidade, ?string $codigo, ?string $classe, string $descricao, float $valor, ?string $natureza = '3.3.90.30', ?string $data = '2027-03-10'): array
    {
        return ['id' => $id, 'numero' => $id, 'unidade' => $unidade, 'tipo' => 'M', 'codigo' => $codigo, 'classe' => $classe, 'classe_nome' => $classe ? 'Classe '.$classe : null,
            'natureza' => $natureza, 'descricao' => $descricao, 'valor' => $valor, 'data' => $data];
    }

    public function test_agrupa_por_codigo_classe_e_descricao_parecida(): void
    {
        $s = ConsolidacaoPca::sugestoes([
            $this->item(1, 'SEMED', '150123', '7510', 'Papel A4 75 g', 5000, data: '2027-04-01'),
            $this->item(2, 'SEMUS', '150123', '7510', 'Papel sulfite A4', 3000, data: '2027-02-01'),
            $this->item(3, 'SEMAD', '200001', '7510', 'Caneta azul', 800),
            $this->item(4, 'SEMED', '200002', '7510', 'Lápis preto', 400),
            $this->item(5, 'SEMAD', null, null, 'Toner para impressora laser modelo X', 2000),
            $this->item(6, 'SEMUS', null, null, 'Toner para impressora laser modelo X preto', 1500),
            $this->item(7, 'SEMUS', null, null, 'Serviço de dedetização dos prédios', 9000, '3.3.90.39'),
        ]);

        $this->assertCount(3, $s);
        $porCriterio = array_column($s, null, 'criterio');
        $this->assertSame([1, 2], $porCriterio['codigo']['itens']);
        $this->assertSame(['SEMED', 'SEMUS'], $porCriterio['codigo']['unidades']);
        $this->assertSame('2027-02-01', $porCriterio['codigo']['data'], 'a contratação conjunta atende o pedido mais cedo');
        $this->assertSame([3, 4], $porCriterio['classe']['itens'], 'itens já agrupados por código não entram de novo');
        $this->assertSame([5, 6], $porCriterio['descricao']['itens']);
        $this->assertStringContainsString('art. 40, V, e § 2º', $s[0]['motivo']);
        $this->assertSame(8000.0, $s[0]['valor'], 'maior valor primeiro');
    }

    public function test_cronograma_confere_a_dotacao_por_natureza(): void
    {
        $c = CronogramaPca::montar(2027, [
            ['natureza' => '3.3.90.30', 'valor' => 6000, 'data' => '2027-03-10'],
            ['natureza' => '3.3.90.30', 'valor' => 5000, 'data' => '2027-03-20'],
            ['natureza' => '3.3.90.39', 'valor' => 9000, 'data' => '2027-07-01'],
            ['natureza' => '4.4.90.52', 'valor' => 2000, 'data' => null],
        ], ['3.3.90.30' => 10000, '3.3.90.39' => 12000, '3.3.90.40' => 500]);

        $this->assertSame(11000.0, $c['meses'][3]);
        $this->assertSame(9000.0, $c['meses'][7]);
        $this->assertSame(2000.0, $c['sem_data']);
        $n = array_column($c['naturezas'], null, 'natureza');
        $this->assertSame(['insuficiente', -1000.0], [$n['3.3.90.30']['situacao'], $n['3.3.90.30']['saldo']]);
        $this->assertSame('coberto', $n['3.3.90.39']['situacao']);
        $this->assertSame('sem_dotacao', $n['4.4.90.52']['situacao']);
        $this->assertSame(0.0, $n['3.3.90.40']['planejado'], 'dotação sem item aparece com planejado zero');
        $this->assertCount(3, $c['alertas']);
        $this->assertStringContainsString('art. 150', implode(' ', $c['alertas']));
    }

    public function test_compras_recorrentes_por_dispensa_em_12_meses(): void
    {
        $hoje = CarbonImmutable::parse('2027-02-15');
        $compra = fn (string $p, string $data, float $v, ?string $nat = '3.3.90.30', ?string $cod = null) => ['processo' => $p, 'data' => $data, 'natureza' => $nat, 'codigo' => $cod, 'valor' => $v];

        $a = FracionamentoHistorico::alertas([
            $compra('1/2026', '2026-04-10', 9000), $compra('2/2026', '2026-09-01', 8000), $compra('3/2026', '2026-12-05', 7000),
            $compra('1/2025', '2025-12-01', 50000), // fora da janela de 12 meses
            $compra('4/2026', '2026-10-01', 30000, '3.3.90.39'), $compra('1/2027', '2027-01-20', 40000, '3.3.90.39'),
            $compra('5/2026', '2026-11-01', 1000, null, '150123'),
        ], $hoje, 62725.59);

        $this->assertCount(2, $a);
        $this->assertSame('Natureza 3.3.90.39', $a[0]['rotulo'], 'acima do limite vem primeiro, mesmo com duas compras');
        $this->assertTrue($a[0]['grave']);
        $this->assertSame(['1/2026', '2/2026', '3/2026'], $a[1]['processos']);
        $this->assertSame(3, $a[1]['meses']);
        $this->assertFalse($a[1]['grave']);
        $this->assertStringContainsString('art. 75, § 1º', $a[1]['motivo']);
    }
}
