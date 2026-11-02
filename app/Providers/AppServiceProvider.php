<?php

namespace App\Providers;

use App\Listeners\AuditarAutenticacao;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Event::subscribe(AuditarAutenticacao::class);

        Password::defaults(fn () => Password::min(10)->letters()->mixedCase()->numbers());

        // Só o administrador geral mexe em outro administrador geral.
        Gate::define('alterar-usuario', fn (User $quem, User $alvo) => $quem->can('usuarios.gerenciar')
            && ($quem->ehAdministradorGeral() || ! $alvo->hasRole('administrador-geral')));

        // Usuários de outro órgão respondem 404, como se não existissem.
        Route::bind('usuario', fn (string $id) => User::visiveisPara(request()->user() ?? abort(401))->findOrFail($id));
    }
}
