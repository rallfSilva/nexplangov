<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\UnidadesPncpPca as U;
use PHPUnit\Framework\TestCase;

/** PCA publicado no PNCP por unidade compradora (art. 12, § 1º, e art. 174). */
class UnidadesPncpPcaTest extends TestCase
{
    /** Secretaria 1 com código; departamento 2 abaixo dela, sem código; secretaria 3 com código; 4 sem código e sem superior. */
    private const UNIDADES = [
        1 => ['parent' => null, 'codigo' => '001'],
        2 => ['parent' => 1, 'codigo' => null],
        3 => ['parent' => null, 'codigo' => '003'],
        4 => ['parent' => null, 'codigo' => ' '],
    ];

    public function test_item_vai_para_a_unidade_ou_para_a_superior_com_codigo(): void
    {
        $this->assertSame(1, U::publicadora(self::UNIDADES, 1));
        $this->assertSame(1, U::publicadora(self::UNIDADES, 2), 'o departamento publica pela secretaria');
        $this->assertSame(3, U::publicadora(self::UNIDADES, 3));
        $this->assertNull(U::publicadora(self::UNIDADES, 4));
        $this->assertNull(U::publicadora(self::UNIDADES, null));
        $this->assertNull(U::publicadora([5 => ['parent' => 6, 'codigo' => null], 6 => ['parent' => 5, 'codigo' => null]], 5), 'ciclo não trava');
    }

    public function test_agrupa_os_itens_por_unidade_publicadora(): void
    {
        $r = U::agrupar(self::UNIDADES, [10 => 1, 11 => 2, 12 => 3, 13 => 4, 14 => null], 1);

        $this->assertSame([1 => [10, 11, 13, 14], 3 => [12]], $r['grupos']);
        $this->assertSame([], $r['sem_unidade']);
    }

    public function test_sem_unidade_padrao_aponta_os_itens_sem_destino(): void
    {
        $r = U::agrupar(self::UNIDADES, [10 => 1, 13 => 4, 14 => null], null);

        $this->assertSame([1 => [10]], $r['grupos']);
        $this->assertSame([13, 14], $r['sem_unidade']);
    }
}
