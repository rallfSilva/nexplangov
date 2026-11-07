<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\DescricaoCatalogo as D;
use PHPUnit\Framework\TestCase;

/** Descrição padronizada no catálogo do órgão (art. 40, § 1º, I, e art. 19, II). */
class DescricaoCatalogoTest extends TestCase
{
    public function test_descricao_vaga_ou_curta_e_barrada(): void
    {
        $this->assertSame(['diversos'], D::termosVagos('Materiais diversos para escritório'));
        $this->assertSame(['em geral'], D::termosVagos('Peças EM GERAL para veículos'));
        $this->assertSame([], D::termosVagos('Papel sulfite A4 diversificado não é termo vago'), 'só a palavra inteira conta');

        $m = D::motivos('M', 'Caneta azul', [['nome' => 'Cor', 'valor' => 'azul']]);
        $this->assertCount(1, $m);
        $this->assertStringContainsString('art. 40, § 1º, I', $m[0]);

        $this->assertNotEmpty(D::motivos('S', 'Serviços gerais de manutenção predial', []));
    }

    public function test_material_precisa_de_atributo_completo(): void
    {
        $desc = 'Papel sulfite A4 branco, resma com 500 folhas';
        $this->assertNotEmpty(D::motivos('M', $desc, []));
        $this->assertNotEmpty(D::motivos('M', $desc, [['nome' => 'Gramatura', 'valor' => '']]));
        $this->assertNotEmpty(D::motivos('M', $desc, [['nome' => 'Cor', 'valor' => 'branca'], ['nome' => 'cor', 'valor' => 'azul']]), 'atributo repetido');
        $this->assertSame([], D::motivos('M', $desc, [['nome' => 'Gramatura', 'valor' => '75 g/m²']]));
        $this->assertSame([], D::motivos('S', 'Manutenção preventiva de aparelhos de ar-condicionado split', []), 'serviço dispensa atributo');
    }

    public function test_normaliza_e_acha_item_parecido(): void
    {
        $this->assertSame('papel sulfite a4 75 g m2', D::normalizar('  Papel SULFITE A4, 75 g/m2 '));
        $this->assertSame('cafe acucar', D::normalizar('Café; Açúcar'));

        $candidatos = [1 => 'Papel sulfite A4 branco 75 g/m² resma 500 folhas', 2 => 'Caneta esferográfica azul ponta média', 3 => 'Papel sulfite A4 branco 75 g/m² pacote 500 folhas'];
        $p = D::parecidos('Papel sulfite A4 branco 75 g/m², resma com 500 folhas', $candidatos);
        $this->assertSame(1, array_key_first($p));
        $this->assertArrayNotHasKey(2, $p);
        $this->assertGreaterThanOrEqual(D::LIMIAR_SEMELHANCA, $p[1]);
    }
}
