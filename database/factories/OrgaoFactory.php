<?php

namespace Database\Factories;

use App\Models\Orgao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Orgao>
 */
class OrgaoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => 'Secretaria de '.fake()->unique()->words(2, true),
            'sigla' => strtoupper(fake()->unique()->lexify('S???')),
            'cnpj' => null,
            'esfera' => 'estadual',
            'uf' => 'RR',
            'municipio' => 'Boa Vista',
            'ativo' => true,
        ];
    }
}
