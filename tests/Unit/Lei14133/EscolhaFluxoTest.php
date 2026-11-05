<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\EscolhaFluxo as E;
use PHPUnit\Framework\TestCase;

/** Fluxo por tipo de processo e por secretaria, e roteiro de unidades. */
class EscolhaFluxoTest extends TestCase
{
    private function f(string $aplicacao = 'todos', ?int $categoria = null, ?int $modalidade = null, ?int $unidade = null): array
    {
        return ['aplicacao' => $aplicacao, 'categoria' => $categoria, 'modalidade' => $modalidade, 'unidade_id' => $unidade];
    }

    public function test_secretaria_vence_modalidade_que_vence_categoria_e_forma(): void
    {
        $processo = ['direta' => false, 'categoria' => 2, 'modalidade' => 6, 'unidade_id' => 10];
        $fluxos = [$this->f(), $this->f('licitacao'), $this->f('todos', 2), $this->f('todos', null, 6), $this->f('todos', null, null, 10)];

        $this->assertSame(4, E::escolher($fluxos, $processo));
        $this->assertSame(3, E::escolher(array_slice($fluxos, 0, 4), $processo));
        $this->assertSame(2, E::escolher(array_slice($fluxos, 0, 3), $processo));
        $this->assertSame(1, E::escolher(array_slice($fluxos, 0, 2), $processo));
    }

    public function test_fluxo_de_outra_secretaria_ou_forma_nao_serve(): void
    {
        $processo = ['direta' => true, 'categoria' => 2, 'modalidade' => 8, 'unidade_id' => 10];
        $this->assertNull(E::pontuar($this->f('licitacao'), $processo));
        $this->assertNull(E::pontuar($this->f('todos', null, null, 11), $processo));
        $this->assertNull(E::pontuar($this->f('todos', null, 6), $processo));
        $this->assertSame(13, E::pontuar($this->f('direta', null, 8, 10), $processo));
        $this->assertNull(E::escolher([$this->f('licitacao')], $processo));
    }

    public function test_roteiro_sugere_a_proxima_unidade(): void
    {
        $this->assertSame(3, E::proximaUnidade([1, 3, 5], 1));
        $this->assertSame(5, E::proximaUnidade([1, 3, 5], 3));
        $this->assertNull(E::proximaUnidade([1, 3, 5], 5), 'fim do roteiro');
        $this->assertSame(1, E::proximaUnidade([1, 3, 5], 9), 'fora do roteiro sugere a primeira');
        $this->assertNull(E::proximaUnidade([], 1));
    }
}
