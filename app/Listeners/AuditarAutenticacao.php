<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

class AuditarAutenticacao
{
    public function login(Login $event): void
    {
        /** @var User $user */
        $user = $event->user;
        $user->forceFill(['ultimo_acesso_em' => now()])->saveQuietly();

        Auditoria::registrar('login', $user, 'Entrou no sistema', usuario: $user);
    }

    public function logout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Auditoria::registrar('logout', $event->user, 'Saiu do sistema', usuario: $event->user);
        }
    }

    public function falhou(Failed $event): void
    {
        $email = mb_substr((string) ($event->credentials['email'] ?? ''), 0, 120);
        $user = $event->user instanceof User ? $event->user : User::where('email', $email)->first();

        Auditoria::registrar('login_falhou', $user, 'Tentativa de entrada recusada para '.$email, usuario: $user);
    }

    public function senhaRedefinida(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            Auditoria::registrar('senha', $event->user, 'Senha redefinida pelo link enviado por e-mail', usuario: $event->user);
        }
    }

    public function doisFatoresAtivado(TwoFactorAuthenticationConfirmed $event): void
    {
        Auditoria::registrar('seguranca', $event->user, 'Ativou a verificação em duas etapas', usuario: $event->user);
    }

    public function doisFatoresDesativado(TwoFactorAuthenticationDisabled $event): void
    {
        Auditoria::registrar('seguranca', $event->user, 'Desativou a verificação em duas etapas', usuario: $event->user);
    }

    public function codigosGerados(RecoveryCodesGenerated $event): void
    {
        Auditoria::registrar('seguranca', $event->user, 'Gerou novos códigos de recuperação', usuario: $event->user);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'login',
            Logout::class => 'logout',
            Failed::class => 'falhou',
            PasswordReset::class => 'senhaRedefinida',
            TwoFactorAuthenticationConfirmed::class => 'doisFatoresAtivado',
            TwoFactorAuthenticationDisabled::class => 'doisFatoresDesativado',
            RecoveryCodesGenerated::class => 'codigosGerados',
        ];
    }
}
