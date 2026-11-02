<?php

namespace Tests;

use App\Models\Orgao;
use App\Models\User;
use Database\Seeders\PerfisSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Roda os perfis antes de cada teste que usa banco (chamar depois do RefreshDatabase). */
    protected function semearPerfis(): void
    {
        $this->seed(PerfisSeeder::class);
    }

    /** Usuário pronto para usar o sistema: com perfil e, se for administrador, com 2FA ativo. */
    protected function usuario(string $perfil, ?Orgao $orgao = null, array $atributos = []): User
    {
        $factory = User::factory()->comPerfil($perfil);

        if (in_array($perfil, ['administrador-geral', 'administrador-orgao'], true)) {
            $factory = $factory->comDoisFatores();
        }

        if ($orgao && $perfil !== 'administrador-geral') {
            $atributos['orgao_id'] = $orgao->id;
        }

        return $factory->create($atributos);
    }
}
