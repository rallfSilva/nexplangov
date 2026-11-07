<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\MatrizRiscos as M;
use PHPUnit\Framework\TestCase;

/** Matriz de riscos estruturada (art. 6º, XXVII; art. 18, X; art. 22; art. 103). */
class MatrizRiscosTest extends TestCase
{
    private function risco(array $extra = []): array
    {
        return $extra + ['risco' => 'Atraso na entrega por falta do produto', 'probabilidade' => 3, 'impacto' => 2, 'alocacao' => 'contratado', 'tratamento' => null];
    }

    public function test_nivel_classe_e_tratamento_obrigatorio_no_risco_alto(): void
    {
        $this->assertSame(['Baixo', 'Médio', 'Alto', 'Extremo'], [M::classe(4)[0], M::classe(6)[0], M::classe(12)[0], M::classe(25)[0]]);
        $this->assertSame([], M::motivos($this->risco()));
        $this->assertNotEmpty(M::motivos($this->risco(['probabilidade' => 4, 'impacto' => 3])), 'nível 12 sem tratamento');
        $this->assertSame([], M::motivos($this->risco(['probabilidade' => 4, 'impacto' => 3, 'tratamento' => 'Exigir prazo de entrega com multa e cadastro reserva'])));
        $this->assertNotEmpty(M::motivos($this->risco(['probabilidade' => 6])));
        $this->assertNotEmpty(M::motivos($this->risco(['alocacao' => 'ninguém'])));
        $this->assertSame(['A matriz de alocação de riscos é obrigatória nesta contratação (art. 22, § 3º).'], M::motivosMatriz([], true));
    }

    public function test_texto_da_peca_numera_e_aloca(): void
    {
        $t = M::textoDaPeca([
            $this->risco(['causa' => 'Mercado restrito', 'responsavel' => 'Fiscal do contrato']),
            $this->risco(['risco' => 'Variação cambial do insumo importado', 'probabilidade' => 2, 'impacto' => 5, 'alocacao' => 'compartilhado', 'tratamento' => 'Cláusula de reajuste por índice setorial']),
        ]);

        $this->assertStringContainsString('1. Atraso na entrega por falta do produto Causa: Mercado restrito. Probabilidade média, impacto baixa, nível 6 (médio).', $t['riscos']);
        $this->assertStringContainsString('2. Cláusula de reajuste por índice setorial', $t['tratamento']);
        $this->assertStringContainsString('1. Aceitar o risco e monitorar. Responsável: Fiscal do contrato.', $t['tratamento']);
        $this->assertStringContainsString('2. Variação cambial do insumo importado: compartilhado.', $t['matriz_alocacao']);
    }

    public function test_sugestao_da_ia_so_aproveita_linhas_validas(): void
    {
        $texto = "Segue a proposta:\n".json_encode([
            ['risco' => 'Atraso na entrega dos notebooks', 'probabilidade' => 3, 'impacto' => 4, 'alocacao' => 'Contratado', 'tratamento' => 'Multa diária e prazo de substituição', 'responsavel' => 'Fiscal'],
            ['risco' => 'Curto', 'probabilidade' => 3, 'impacto' => 3, 'alocacao' => 'contratado'],
            ['risco' => 'Falha na garantia técnica do equipamento', 'probabilidade' => 9, 'impacto' => 3, 'alocacao' => 'contratado'],
            'lixo',
        ], JSON_UNESCAPED_UNICODE);

        $r = M::lerSugestao($texto);
        $this->assertCount(1, $r);
        $this->assertSame('contratado', $r[0]['alocacao']);
        $this->assertSame([], M::lerSugestao('não é JSON'));
    }
}
