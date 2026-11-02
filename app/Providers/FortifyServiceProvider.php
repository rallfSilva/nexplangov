<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower($request->input('email')))->first();

            if (! $user || ! Hash::check($request->input('password'), $user->password)) {
                return null;
            }

            if (! $user->ativo || ($user->orgao_id !== null && ! $user->orgao?->ativo)) {
                Auditoria::registrar('login_falhou', $user, 'Entrada recusada: acesso desativado', usuario: $user);

                throw ValidationException::withMessages([
                    'email' => 'Seu acesso está desativado. Procure o administrador do órgão.',
                ]);
            }

            return $user;
        });

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.esqueci-senha'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.redefinir-senha', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.dois-fatores'));
        Fortify::confirmPasswordView(fn () => view('auth.confirmar-senha'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
