<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\IndicadoresPca as I;
use PHPUnit\Framework\TestCase;

/** Indicadores de execução do PCA (art. 12, VII; art. 18). */
class IndicadoresPcaTest extends TestCase
{
    public function test_aderencia_fora_do_pca_atraso_e_planejamento_tardio(): void
    {
        $r = I::calcular([
            ['unidade' => 'SEMED', 'planejado' => true, 'tardio' => false, 'valor' => 1000],
            ['unidade' => 'SEMED', 'planejado' => true, 'tardio' => true, 'valor' => 500],
            ['unidade' => 'GAB', 'planejado' => false, 'tardio' => false, 'valor' => 250],
            ['unidade' => 'GAB', 'planejado' => true, 'tardio' => false, 'valor' => 100],
        ], [
            ['unidade' => 'SEMED', 'situacao' => 'aceita', 'data' => '2027-02-01', 'valor' => 70],
            ['unidade' => 'SEMED', 'situacao' => 'consolidada', 'data' => '2027-04-01', 'valor' => 30],
            ['unidade' => 'GAB', 'situacao' => 'atendida', 'data' => '2027-01-01', 'valor' => 999],
            ['unidade' => 'GAB', 'situacao' => 'enviada', 'data' => '2027-01-01', 'valor' => 999],
        ], '2027-03-01');

        $this->assertSame(75.0, $r['aderencia']);
        $this->assertSame(1600.0, $r['valor_planejado']);
        $this->assertSame([1, 250.0], [$r['fora_quantidade'], $r['fora_valor']]);
        $this->assertSame([1, 70.0], [$r['atraso_quantidade'], $r['atraso_valor']], 'só o aprovado que passou da data sem virar processo');
        $this->assertSame([2, 50.0], [$r['tardios'], $r['tardio_indice']]);
        $this->assertSame(['unidade' => 'GAB', 'planejados' => 1, 'total' => 2, 'aderencia' => 50.0], $r['por_unidade'][0]);
    }

    public function test_sem_processos_nao_divide_por_zero(): void
    {
        $r = I::calcular([], [], '2027-01-01');
        $this->assertSame([0.0, 0.0], [$r['aderencia'], $r['tardio_indice']]);
    }

    public function test_valores_por_unidade_e_calendario(): void
    {
        $u = I::valoresPorUnidade([
            ['unidade' => 'GAB', 'categoria' => 2, 'valor' => 10],
            ['unidade' => 'SEMED', 'categoria' => 2, 'valor' => 100],
            ['unidade' => 'SEMED', 'categoria' => 8, 'valor' => 50],
        ]);
        $this->assertSame(['unidade' => 'SEMED', 'total' => 150.0, 'categorias' => [2 => 100.0, 8 => 50.0]], $u[0]);

        $c = I::calendario(2027, [['data' => '2027-03-10', 'valor' => 10], ['data' => '2027-03-20', 'valor' => 5], ['data' => '2026-12-01', 'valor' => 1], ['data' => null, 'valor' => 1]]);
        $this->assertSame(['quantidade' => 2, 'valor' => 15.0], $c['meses'][3]);
        $this->assertSame(2, $c['sem_data']);
    }
}
