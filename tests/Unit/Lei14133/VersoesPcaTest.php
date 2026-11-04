<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\VersoesPca;
use PHPUnit\Framework\TestCase;

/** Versões do PCA: o que mudou entre duas aprovações (art. 12, VII e § 1º). */
class VersoesPcaTest extends TestCase
{
    private function item(int $n, string $d, $qtd, $vu): array
    {
        return ['numero' => $n, 'descricao' => $d, 'material_servico' => 'M', 'categoria' => 2, 'unidade_fornecimento' => 'UN',
            'quantidade' => $qtd, 'valor_unitario_estimado' => $vu, 'valor_total_estimado' => round($qtd * $vu, 2), 'prioridade' => 2];
    }

    public function test_aponta_incluidos_excluidos_e_alterados_pelo_numero_do_item(): void
    {
        $antes = [$this->item(1, 'Papel', 100, 30), $this->item(2, 'Caneta', 50, 2), $this->item(3, 'Toner', 5, 300)];
        $depois = [$this->item(1, 'Papel', '100.0000', '30.0000'), $this->item(3, 'Toner', 8, 300), $this->item(4, 'Grampeador', 10, 25)];

        $d = VersoesPca::comparar($antes, $depois);

        $this->assertSame([4], array_column($d['incluidos'], 'numero'));
        $this->assertSame([2], array_column($d['excluidos'], 'numero'));
        $this->assertCount(1, $d['alterados'], '"100.0000" e 100 são o mesmo valor');
        $this->assertSame(3, $d['alterados'][0]['numero']);
        $this->assertSame(['quantidade', 'valor_total_estimado'], array_keys($d['alterados'][0]['mudancas']));
        $this->assertSame(['de' => 5, 'para' => 8], $d['alterados'][0]['mudancas']['quantidade']);
        $this->assertSame([4600.0, 5650.0], [$d['valor_antes'], $d['valor_depois']]);
    }

    public function test_numero_de_item_nunca_volta_e_justificativa_tem_minimo(): void
    {
        $this->assertSame(1, VersoesPca::proximoNumero([]));
        $this->assertSame(8, VersoesPca::proximoNumero([1, 2, 7, 3]));
        $this->assertFalse(VersoesPca::justificativaValida('   curta  '));
        $this->assertFalse(VersoesPca::justificativaValida(null));
        $this->assertTrue(VersoesPca::justificativaValida('Inclusão de demanda nova da Saúde'));
    }
}
