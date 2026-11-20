<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\ConsolidacaoDfd as C;
use PHPUnit\Framework\TestCase;

/** Consolidação dos itens dos DFDs aprovados no PCA (art. 12, VII). */
class ConsolidacaoDfdTest extends TestCase
{
    private function dfd(int $id, ?int $unidade, ?string $codigo, float $qtd, float $valor, array $extra = []): array
    {
        return $extra + ['id' => $id, 'unidade_id' => $unidade, 'material_servico' => 'M', 'codigo' => $codigo, 'descricao' => 'Papel A4 branco', 'unidade_fornecimento' => 'resma',
            'quantidade' => $qtd, 'valor_unitario' => $valor, 'categoria' => 2, 'data' => '2027-05-01', 'prioridade' => 2];
    }

    public function test_mesmo_codigo_na_mesma_unidade_soma_e_faz_media_ponderada(): void
    {
        $l = C::planejar([
            $this->dfd(1, 7, '150000', 100, 20.0),
            $this->dfd(2, 7, '150000', 300, 24.0, ['unidade_fornecimento' => 'RESMA ', 'data' => '2027-03-01', 'prioridade' => 1]),
        ], []);

        $this->assertCount(1, $l);
        $this->assertNull($l[0]['item_pca_id']);
        $this->assertSame([1, 2], $l[0]['itens_dfd']);
        $this->assertSame(400.0, $l[0]['quantidade']);
        $this->assertSame(23.0, $l[0]['valor_unitario'], '(100 × 20 + 300 × 24) / 400');
        $this->assertSame('2027-03-01', $l[0]['data'], 'vale a data mais cedo');
        $this->assertSame(1, $l[0]['prioridade'], 'vale a prioridade mais alta');
        $this->assertSame(2, $l[0]['categoria']);
    }

    public function test_unidades_diferentes_ficam_em_linhas_separadas(): void
    {
        $l = C::planejar([$this->dfd(1, 7, '150000', 10, 20), $this->dfd(2, 8, '150000', 10, 20)], []);

        $this->assertCount(2, $l);
    }

    public function test_sem_codigo_so_junta_descricao_igual(): void
    {
        $l = C::planejar([
            $this->dfd(1, 7, null, 10, 20, ['descricao' => 'Água mineral, galão 20 L']),
            $this->dfd(2, 7, null, 5, 20, ['descricao' => 'agua mineral galao 20 l']),
            $this->dfd(3, 7, null, 5, 20, ['descricao' => 'Copo descartável 200 ml']),
        ], []);

        $this->assertCount(2, $l);
        $this->assertSame([1, 2], $l[0]['itens_dfd']);
    }

    public function test_linha_que_ja_esta_no_plano_recebe_as_quantidades(): void
    {
        $pca = [['id' => 50, 'unidade_id' => 7, 'material_servico' => 'M', 'codigo' => '150000', 'descricao' => 'Papel', 'unidade_fornecimento' => 'RESMA',
            'quantidade' => 100.0, 'valor_unitario' => 20.0, 'data' => '2027-02-01', 'prioridade' => 3]];
        $outra = [['id' => 51, 'unidade_id' => 8, 'material_servico' => 'M', 'codigo' => '150000', 'descricao' => 'Papel', 'unidade_fornecimento' => 'RESMA',
            'quantidade' => 1.0, 'valor_unitario' => 1.0, 'data' => null, 'prioridade' => 3]];

        $l = C::planejar([$this->dfd(1, 7, '150000', 100, 30)], [...$pca, ...$outra]);

        $this->assertCount(1, $l, 'só muda a linha que recebe item');
        $this->assertSame(50, $l[0]['item_pca_id']);
        $this->assertSame(200.0, $l[0]['quantidade']);
        $this->assertSame(25.0, $l[0]['valor_unitario']);
        $this->assertSame('2027-02-01', $l[0]['data']);
        $this->assertSame(2, $l[0]['prioridade']);
    }

    public function test_progresso_por_unidade(): void
    {
        $p = C::progresso([
            ['unidade' => 'SEMED', 'situacao' => 'consolidada', 'valor' => 100],
            ['unidade' => 'SEMED', 'situacao' => 'aceita', 'valor' => 50],
            ['unidade' => 'SEMED', 'situacao' => 'enviada', 'valor' => 50],
            ['unidade' => 'GAB', 'situacao' => 'consolidada', 'valor' => 10],
        ]);

        $this->assertSame(['unidade' => 'GAB', 'total' => 1, 'consolidados' => 1, 'valor' => 10.0, 'percentual' => 100], $p[0]);
        $this->assertSame(['unidade' => 'SEMED', 'total' => 3, 'consolidados' => 1, 'valor' => 200.0, 'percentual' => 33], $p[1]);
    }
}
