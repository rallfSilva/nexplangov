<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\PrevisaoPca;
use PHPUnit\Framework\TestCase;

/** Trava de execução cruzada com o PCA (art. 12, VII, e art. 18, § 1º, II). */
class PrevisaoPcaTest extends TestCase
{
    public function test_fora_do_plano_so_abre_com_justificativa_minima(): void
    {
        $this->assertCount(1, PrevisaoPca::motivos(false, false, null, 2026, null));
        $this->assertCount(1, PrevisaoPca::motivos(false, false, null, 2026, '  curta demais  '));
        $this->assertSame([], PrevisaoPca::motivos(false, false, null, 2026, 'Quebra inesperada dos bebedouros de quatro escolas.'));
    }

    public function test_item_precisa_ser_de_plano_aprovado_do_mesmo_exercicio(): void
    {
        $this->assertSame([], PrevisaoPca::motivos(true, true, 2026, 2026, null));
        $this->assertStringContainsString('não aprovado', PrevisaoPca::motivos(true, false, 2026, 2026, null)[0]);
        $this->assertStringContainsString('PCA de 2027', PrevisaoPca::motivos(true, true, 2027, 2026, null)[0]);
    }

    public function test_cruzamento_sugere_itens_com_mais_palavras_em_comum(): void
    {
        $itens = [
            ['id' => 1, 'descricao' => 'Papel A4 75 g/m², resma com 500 folhas'],
            ['id' => 2, 'descricao' => 'Kit de material escolar para o ensino fundamental'],
            ['id' => 3, 'descricao' => 'Material de limpeza e higiene'],
        ];

        $this->assertSame([2, 3], PrevisaoPca::sugerir('Aquisição de kits de material escolar para o ensino fundamental', $itens));
        $this->assertSame([], PrevisaoPca::sugerir('Serviço de vigilância patrimonial', $itens));
        $this->assertSame([], PrevisaoPca::sugerir('para com', $itens), 'palavras curtas e genéricas não contam');
    }
}
