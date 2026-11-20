<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\CotacaoAutomatica;
use App\Licitacao\Regras\PesquisaPrecos;
use PHPUnit\Framework\TestCase;

/** Cotação automática por IA (art. 23, § 1º, II): a IA classifica, a regra escolhe e calcula. */
class CotacaoAutomaticaTest extends TestCase
{
    public function test_palavras_chave_tiram_acento_e_palavras_vazias(): void
    {
        $this->assertSame(['papel', 'a4', '75g', 'branco'], CotacaoAutomatica::palavrasChave('Papel A4 75g, branco, pacote com 500 folhas', 4));
        $this->assertSame(1.0, CotacaoAutomatica::semelhanca(['papel', 'a4'], 'PAPEL SULFITE A4'));
        $this->assertSame(0.5, CotacaoAutomatica::semelhanca(['papel', 'a4'], 'Papel kraft'));
    }

    public function test_catalogo_so_aceita_codigo_que_estava_na_lista(): void
    {
        $this->assertSame(['codigo' => '461777', 'termos' => 'papel a4'], CotacaoAutomatica::lerCatalogo('Resposta: {"codigo": "461777", "termos": "papel  a4"}', ['461777', '123']));
        $this->assertNull(CotacaoAutomatica::lerCatalogo('{"codigo": "999999", "termos": "x y"}', ['461777'])['codigo'], 'código inventado é recusado');
        $this->assertSame(['codigo' => null, 'termos' => null], CotacaoAutomatica::lerCatalogo('não sei', ['1']));
    }

    public function test_avaliacao_ignora_indice_inexistente_e_incompativel(): void
    {
        $r = CotacaoAutomatica::lerAvaliacao(json_encode(['avaliacoes' => [
            ['i' => 0, 'compativel' => true, 'motivo' => 'Mesmo papel, mesma unidade'],
            ['i' => 1, 'compativel' => false, 'motivo' => 'Caixa com 10 resmas'],
            ['i' => 7, 'compativel' => true],
        ], 'justificativa' => 'Critérios: mesma especificação e unidade (art. 23, § 1º, II).']), 3);

        $this->assertSame([0 => 'Mesmo papel, mesma unidade'], $r['compativeis']);
        $this->assertStringContainsString('art. 23', $r['justificativa']);
    }

    public function test_seleciona_os_mais_recentes_sem_repetir_compra(): void
    {
        $c = [
            ['id' => 'a', 'valor' => 10.0, 'data' => '2026-03-01'],
            ['id' => 'b', 'valor' => 50.0, 'data' => '2026-09-01'],
            ['id' => 'b', 'valor' => 50.0, 'data' => '2026-09-01'], // repetida
            ['id' => 'c', 'valor' => 12.0, 'data' => '2026-08-01'],
            ['id' => 'd', 'valor' => 11.0, 'data' => '2026-10-01'], // incompatível
        ];

        $this->assertSame([1, 3, 0], CotacaoAutomatica::selecionar($c, [0 => 'ok', 1 => 'ok', 2 => 'ok', 3 => 'ok'], 3));
        $this->assertSame([1], CotacaoAutomatica::selecionar($c, [0 => 'ok', 1 => 'ok', 3 => 'ok'], 1));
    }

    public function test_media_ponderada_e_estatisticas(): void
    {
        $r = (new PesquisaPrecos)->calcular([
            ['valor' => 10, 'fonte' => 'contratacoes_similares', 'data' => '2026-10-01', 'quantidade' => 100],
            ['valor' => 12, 'fonte' => 'contratacoes_similares', 'data' => '2026-10-01', 'quantidade' => 300],
            ['valor' => 11, 'fonte' => 'contratacoes_similares', 'data' => '2026-10-01'], // sem quantidade: peso 1
        ], 'ponderada', '2026-11-02');

        $this->assertSame(['minimo' => 10.0, 'media' => 11.0, 'mediana' => 11.0, 'media_ponderada' => round((1000 + 3600 + 11) / 401, 4)], $r['estatisticas']);
        $this->assertSame($r['estatisticas']['media_ponderada'], $r['valor_unitario']);
        $this->assertFalse($r['discrepante']);
    }

    public function test_alerta_de_precos_discrepantes(): void
    {
        $r = (new PesquisaPrecos(0.9))->calcular([
            ['valor' => 10, 'fonte' => 'contratacoes_similares', 'data' => '2026-10-01'],
            ['valor' => 15, 'fonte' => 'contratacoes_similares', 'data' => '2026-10-01'],
            ['valor' => 18, 'fonte' => 'contratacoes_similares', 'data' => '2026-10-01'],
        ], 'mediana', '2026-11-02');

        $this->assertTrue($r['discrepante']);
        $this->assertStringContainsString('Preços discrepantes', implode(' ', $r['alertas']));
    }
}
