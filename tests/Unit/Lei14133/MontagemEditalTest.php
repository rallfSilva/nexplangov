<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\DiasUteis;
use App\Licitacao\Regras\Garantia;
use App\Licitacao\Regras\MontagemEdital;
use Database\Seeders\ModelosPadrao;
use PHPUnit\Framework\TestCase;

/** "Gerar edital completo": preenchimento dos trechos [informe] dos modelos de edital (arts. 25, 46, 55, 90, 96 a 99). */
class MontagemEditalTest extends TestCase
{
    private function regra(): MontagemEdital
    {
        return new MontagemEdital(new DiasUteis, new Garantia);
    }

    private function modelo(string $codigo): string
    {
        foreach (ModelosPadrao::catalogo() as $m) {
            if ($m['codigo'] === $codigo) {
                return $m['conteudo']['texto'];
            }
        }
        $this->fail("Modelo {$codigo} não existe.");
    }

    private function dados(array $extra = []): array
    {
        return $extra + [
            'sessao_em' => '2026-12-15 09:00', 'local' => 'Compras.gov.br', 'modo_disputa' => 'Aberto', 'prazo_assinatura' => 5,
            'garantia_pct' => 0.0, 'habilitacao' => 'Atestado de capacidade técnica de fornecimento compatível com o objeto.', 'cidade' => 'Vale Verde/RR',
            'bens' => true, 'criterio' => 1,
            'itens' => [['numero' => 1, 'descricao' => 'Caneta esferográfica azul', 'beneficio' => 1], ['numero' => 2, 'descricao' => 'Papel A4', 'beneficio' => 3]],
        ];
    }

    public function test_pregao_eletronico_sai_sem_pendencias_com_os_dados_completos(): void
    {
        $r = $this->regra()->montar($this->modelo('edital-pregao-eletronico'), $this->dados());
        $t = $r['texto'];

        $this->assertSame([], $r['pendentes'], 'com os dados completos, nada fica entre colchetes');
        $this->assertStringContainsString('(modo de disputa aberto)', $t);
        $this->assertStringContainsString('em 15/12/2026, às 09h00 (horário de Brasília)', $t);
        $this->assertStringContainsString('em plataforma eletrônica (Compras.gov.br)', $t);
        $this->assertStringContainsString('em até 5 dias úteis contados da convocação', $t);
        $this->assertStringContainsString('art. 90, § 1º', $t);
        $this->assertStringContainsString('Garantia de execução: não exigida', $t);
        $this->assertStringContainsString("Item 1, Caneta esferográfica azul: participação exclusiva de microempresas e empresas de pequeno porte.\nItem 2, Papel A4: cota reservada", $t);
        $this->assertStringContainsString('5.3. Exigências de habilitação desta licitação, conforme o Termo de Referência (Anexo I): Atestado', $t);
        $this->assertStringContainsString('1.3. A licitação será dividida em 2 itens', $t);
        $this->assertStringContainsString('Anexo III, Mapa de Riscos', $t);
        $this->assertStringContainsString('Vale Verde/RR, {{data}}.', $t);
        $this->assertStringNotContainsString('Se orçamento sigiloso', $t);
        $this->assertStringContainsString('Divulgue o edital no PNCP até 03/12/2026: são pelo menos 8 dias úteis', $r['alertas'][0]);
    }

    public function test_o_que_nao_tem_dado_continua_pendente(): void
    {
        $r = $this->regra()->montar($this->modelo('edital-pregao-eletronico'), ['itens' => []]);

        $this->assertContains('informe a data e a hora da sessão', $r['pendentes']);
        $this->assertContains('aberto, fechado e aberto ou aberto e fechado', $r['pendentes']);
        $this->assertContains('não exigida ou informe o percentual, até 5%, ou até 10% com justificativa', $r['pendentes']);
        $this->assertContains('Local', $r['pendentes']);
        $this->assertNotEmpty(array_filter($r['pendentes'], fn ($p) => str_starts_with($p, 'informe o benefício de cada item')));
        $this->assertNotEmpty(array_filter($r['pendentes'], fn ($p) => str_starts_with($p, 'informe as exigências de qualificação')));
    }

    public function test_garantia_segue_os_limites_da_lei(): void
    {
        $base = $this->modelo('edital-pregao-eletronico');

        $ok = $this->regra()->montar($base, $this->dados(['garantia_pct' => 5.0]));
        $this->assertStringContainsString('Garantia de execução: 5% do valor inicial do contrato, em caução em dinheiro', $ok['texto']);

        $semJust = $this->regra()->montar($base, $this->dados(['garantia_pct' => 8.0]));
        $this->assertStringContainsString('exige justificativa', $semJust['alertas'][1]);
        $this->assertStringContainsString('Garantia de execução: [', $semJust['texto'], 'garantia inválida não entra no texto');

        $comJust = $this->regra()->montar($base, $this->dados(['garantia_pct' => 8.0, 'garantia_justificativa' => 'alta complexidade técnica do objeto']));
        $this->assertStringContainsString('8% do valor inicial', $comJust['texto']);
        $this->assertStringContainsString('art. 98, parágrafo único', $comJust['texto']);

        $acima = $this->regra()->montar($base, $this->dados(['garantia_pct' => 12.0, 'garantia_justificativa' => 'x']));
        $this->assertStringContainsString('até 10%', $acima['alertas'][1]);
    }

    public function test_orcamento_sigiloso_nao_divulga_o_valor(): void
    {
        $t = $this->regra()->montar($this->modelo('edital-pregao-eletronico'), $this->dados(['orcamento_sigiloso' => true]))['texto'];

        $this->assertStringNotContainsString('{{valor_estimado}}', $t);
        $this->assertStringContainsString('caráter sigiloso', $t);
        $this->assertStringContainsString('(art. 24)', $t);
    }

    public function test_obra_regime_visita_e_seguro(): void
    {
        $base = $this->modelo('edital-concorrencia-obras');
        $d = $this->dados(['bens' => false, 'regime' => 'preco_global', 'visita' => 'facultativa', 'local' => null]);

        $r = $this->regra()->montar($base, $d);
        $this->assertStringContainsString('no regime de empreitada por preço global', $r['texto']);
        $this->assertStringContainsString('é facultativa', $r['texto']);
        $this->assertStringContainsString('constam do Anexo I (Termo de Referência ou projeto básico)', $r['texto']);
        $this->assertStringContainsString('5-A.3. Não se exige seguro-garantia', $r['texto']);
        $this->assertStringContainsString('10 dias úteis', $r['alertas'][0]);

        $obrig = $this->regra()->montar($base, ['visita' => 'obrigatoria'] + $d);
        $this->assertContains('Visita técnica obrigatória exige justificativa no edital (art. 63, § 2º).', $obrig['alertas']);

        $vulto = $this->regra()->montar($base, ['grande_vulto_engenharia' => true, 'garantia_pct' => 30.0] + $d);
        $this->assertStringContainsString('30% do valor inicial do contrato, na modalidade seguro-garantia com cláusula de retomada', $vulto['texto']);
        $this->assertStringContainsString('5-A.3. Por se tratar de obra de grande vulto', $vulto['texto']);
    }

    public function test_presencial_usa_o_endereco(): void
    {
        $t = $this->regra()->montar($this->modelo('edital-pregao-presencial'), $this->dados(['local' => 'Rua das Flores, 100, sala 2, Vale Verde']))['texto'];

        $this->assertStringContainsString('no endereço Rua das Flores, 100, sala 2, Vale Verde, em 15/12/2026', $t);
    }

    public function test_prazo_minimo_de_divulgacao(): void
    {
        $r = $this->regra();
        $this->assertSame(8, $r->prazoMinimo(true, 1)['dias']);
        $this->assertSame(15, $r->prazoMinimo(true, 6)['dias']);
        $this->assertSame(10, $r->prazoMinimo(false, 2)['dias']);
        $this->assertSame(35, $r->prazoMinimo(false, 4)['dias']);
        $this->assertSame(15, $r->prazoMinimo(false, 5)['dias']);
    }

    public function test_le_a_proposta_da_ia_so_com_o_que_vale(): void
    {
        $p = $this->regra()->lerProposta('Segue: {"habilitacao": "Atestado de capacidade técnica.", "prazo_assinatura": 90, "garantia_pct": 8, "garantia_justificativa": null, "regime": "preco_global", "visita": "talvez"}');

        $this->assertSame(['habilitacao' => 'Atestado de capacidade técnica.', 'regime' => 'preco_global'], $p, 'prazo fora de 1 a 30, garantia de 8% sem justificativa e visita inválida são descartados');
        $this->assertSame([], $this->regra()->lerProposta('não sei'));
        $this->assertSame(['prazo_assinatura' => 5, 'garantia_pct' => 0.0], $this->regra()->lerProposta('{"prazo_assinatura": 5, "garantia_pct": 0}'));
    }

    public function test_le_regime_e_garantia_das_outras_pecas(): void
    {
        $this->assertSame('preco_global', MontagemEdital::regimeNoTexto('Execução pelo regime de empreitada por preço global (art. 46).'));
        $this->assertSame('semi_integrada', MontagemEdital::regimeNoTexto('Adota-se a contratação semi-integrada.'));
        $this->assertNull(MontagemEdital::regimeNoTexto('pelo regime de [empreitada por preço global, unitário, integral, semi-integrada ou integrada]'));

        $this->assertSame(0.0, MontagemEdital::garantiaNoContrato("CLÁUSULA 8ª, DA GARANTIA (art. 92, XII)\nNão exigida, dado o baixo risco."));
        $this->assertSame(5.0, MontagemEdital::garantiaNoContrato("CLÁUSULA 8ª, DA GARANTIA (art. 92, XII)\nSeguro-garantia de 5% do valor."));
        $this->assertNull(MontagemEdital::garantiaNoContrato("CLÁUSULA 8ª, DA GARANTIA (art. 92, XII)\n[Não exigida ou informe a modalidade e o percentual]"));
    }
}
