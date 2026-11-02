<?php

namespace Database\Factories;

use App\Models\Orgao;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unidade>
 */
class UnidadeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'orgao_id' => Orgao::factory(),
            'sigla' => strtoupper(fake()->unique()->lexify('U???')),
            'nome' => 'Departamento de '.fake()->words(2, true),
            'tipo' => 'departamento',
            'ativo' => true,
        ];
    }
}
