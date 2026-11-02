<?php

namespace App\Console\Commands;

use App\Models\Orgao;
use App\Models\User;
use Database\Seeders\FluxosPadrao;
use Database\Seeders\ModelosPadrao;
use Database\Seeders\PerfisSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('nexplangov:instalar
    {--orgao-nome= : Nome do primeiro órgão}
    {--orgao-sigla= : Sigla do primeiro órgão}
    {--nome= : Nome do administrador geral}
    {--email= : E-mail do administrador geral}
    {--senha= : Senha do administrador geral (se omitida, será perguntada)}')]
#[Description('Prepara uma instalação nova: perfis, primeiro órgão e administrador geral')]
class InstalarNexplangov extends Command
{
    public function handle(): int
    {
        $this->call('db:seed', ['--class' => PerfisSeeder::class, '--force' => true]);

        $orgaoNome = $this->option('orgao-nome') ?? text('Nome do órgão', 'Secretaria de Estado de ...', required: true);
        $orgaoSigla = $this->option('orgao-sigla') ?? text('Sigla do órgão', 'SELC', required: true);
        $nome = $this->option('nome') ?? text('Nome do administrador geral', required: true);
        $email = $this->option('email') ?? text('E-mail do administrador geral', required: true);
        $senha = $this->option('senha') ?? password('Senha (mínimo 10 caracteres, com maiúscula, minúscula e número)', required: true);

        $validador = Validator::make(
            ['email' => $email, 'senha' => $senha, 'sigla' => $orgaoSigla],
            [
                'email' => ['required', 'email', 'unique:users,email'],
                'senha' => ['required', Password::defaults()],
                'sigla' => ['required', 'max:30'],
            ],
        );

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $orgao = Orgao::firstOrCreate(['sigla' => mb_strtoupper($orgaoSigla)], ['nome' => $orgaoNome]);

        ModelosPadrao::instalarPara($orgao);
        FluxosPadrao::instalarPara($orgao);

        $admin = User::create([
            'name' => $nome,
            'email' => mb_strtolower($email),
            'password' => $senha,
            'orgao_id' => null,
        ]);
        $admin->assignRole('administrador-geral');

        $this->info("Órgão {$orgao->sigla} pronto e administrador geral {$admin->email} criado.");
        $this->line('No primeiro acesso o sistema vai pedir a ativação da verificação em duas etapas.');

        return self::SUCCESS;
    }
}
