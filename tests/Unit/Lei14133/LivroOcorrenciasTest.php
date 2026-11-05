<?php

namespace Tests\Unit\Lei14133;

use App\Licitacao\Regras\LivroOcorrencias as L;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Livro de ocorrências do contrato (art. 117, §§ 1º e 2º). */
class LivroOcorrenciasTest extends TestCase
{
    private function d(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data);
    }

    public function test_so_fiscal_ou_gestor_designado_anota(): void
    {
        $m = L::validar('ocorrencia', 'Equipe chegou com 2 horas de atraso.', $this->d('2026-10-10'), $this->d('2026-10-11'), $this->d('2026-01-01'), false, false);

        $this->assertStringContainsString('art. 117', $m[0]);
    }

    public function test_data_futura_ou_antes_da_vigencia_e_recusada(): void
    {
        $this->assertCount(1, L::validar('ocorrencia', 'Equipe chegou com 2 horas de atraso.', $this->d('2026-10-12'), $this->d('2026-10-11'), $this->d('2026-01-01'), true, false));
        $this->assertCount(1, L::validar('ocorrencia', 'Equipe chegou com 2 horas de atraso.', $this->d('2025-12-31'), $this->d('2026-10-11'), $this->d('2026-01-01'), true, false));
    }

    public function test_retificacao_precisa_apontar_a_anotacao(): void
    {
        $this->assertNotSame([], L::validar('retificacao', 'Corrige a data informada na anotação anterior.', $this->d('2026-10-10'), $this->d('2026-10-11'), $this->d('2026-01-01'), true, false));
        $this->assertSame([], L::validar('retificacao', 'Corrige a data informada na anotação anterior.', $this->d('2026-10-10'), $this->d('2026-10-11'), $this->d('2026-01-01'), true, true));
    }

    public function test_hash_encadeia_e_muda_com_qualquer_campo(): void
    {
        $a = L::hash(['numero' => 1, 'descricao' => 'texto'], null);

        $this->assertSame($a, L::hash(['descricao' => 'texto', 'numero' => 1], null), 'ordem das chaves não importa');
        $this->assertNotSame($a, L::hash(['numero' => 1, 'descricao' => 'texto!'], null));
        $this->assertNotSame($a, L::hash(['numero' => 1, 'descricao' => 'texto'], str_repeat('a', 64)));
    }
}
