<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\ComparacaoPcaPncp as C;
use PHPUnit\Framework\TestCase;

/** Conferência do PCA publicado no PNCP com o plano (art. 12, § 1º, e art. 174). */
class ComparacaoPcaPncpTest extends TestCase
{
    public function test_separa_novos_removidos_e_alterados(): void
    {
        $locais = [
            ['numero' => 1, 'descricao' => 'Papel A4', 'quantidade' => 100.0, 'valor_unitario' => 25.0, 'data' => '2027-03-01', 'codigo' => '150000'],
            ['numero' => 2, 'descricao' => 'Caneta', 'quantidade' => 50.0, 'valor_unitario' => 2.0, 'data' => '2027-03-01', 'codigo' => null],
            ['numero' => 4, 'descricao' => 'Grampeador', 'quantidade' => 5.0, 'valor_unitario' => 30.0, 'data' => null, 'codigo' => null],
        ];
        $remotos = [
            ['numeroItem' => 1, 'descricao' => 'Papel A4 ', 'quantidade' => 100, 'valorUnitario' => 25.00001, 'dataDesejada' => '2027-03-01T00:00:00', 'codigoItem' => '150000'],
            ['numeroItem' => 2, 'descricao' => 'Caneta', 'quantidade' => 40, 'valorUnitario' => 2, 'dataDesejada' => '2027-03-01', 'codigoItem' => ''],
            ['numeroItem' => 3, 'descricao' => 'Clipe', 'quantidade' => 1, 'valorUnitario' => 1, 'dataDesejada' => '2027-03-01'],
        ];

        $r = C::comparar($locais, $remotos);

        $this->assertSame([4], $r['novos']);
        $this->assertSame([3], $r['removidos']);
        $this->assertSame(1, $r['iguais']);
        $this->assertSame([['campo' => 'Quantidade', 'aqui' => '50', 'pncp' => '40']], $r['alterados'][2]);
    }
}
