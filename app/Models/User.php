<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['orgao_id', 'name', 'email', 'cpf', 'password', 'ativo'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /** Perfis com o rótulo exibido na tela, na ordem de poder. */
    public const PERFIS = [
        'administrador-geral' => 'Administrador geral',
        'administrador-orgao' => 'Administrador do órgão',
        'autoridade' => 'Autoridade competente',
        'agente-contratacao' => 'Agente de contratação ou pregoeiro',
        'planejamento' => 'Planejamento e compras',
        'juridico' => 'Assessoria jurídica',
        'gestor-contrato' => 'Gestor ou fiscal de contrato',
        'requisitante' => 'Requisitante',
        'auditor' => 'Controle interno',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'ultimo_acesso_em' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
        ];
    }

    public function orgao(): BelongsTo
    {
        return $this->belongsTo(Orgao::class);
    }

    public function ehAdministradorGeral(): bool
    {
        return $this->orgao_id === null && $this->hasRole('administrador-geral');
    }

    public function exigeDoisFatores(): bool
    {
        return $this->hasAnyRole(['administrador-geral', 'administrador-orgao']);
    }

    public function temDoisFatoresAtivo(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** Usuários que o usuário logado pode ver: os do próprio órgão (ou todos, para o administrador geral). */
    public function scopeVisiveisPara(Builder $query, User $usuario): Builder
    {
        return $usuario->orgao_id === null ? $query : $query->where('orgao_id', $usuario->orgao_id);
    }

    public function perfilPrincipal(): ?string
    {
        $nomes = $this->getRoleNames();

        foreach (array_keys(self::PERFIS) as $perfil) {
            if ($nomes->contains($perfil)) {
                return $perfil;
            }
        }

        return null;
    }

    public function iniciais(): string
    {
        $partes = preg_split('/\s+/', trim($this->name)) ?: [];
        $primeira = $partes[0] ?? '';
        $ultima = count($partes) > 1 ? end($partes) : '';

        return mb_strtoupper(mb_substr($primeira, 0, 1).mb_substr($ultima, 0, 1));
    }

    /** CPF com máscara para exibição: ***.456.789-** */
    public function cpfMascarado(): ?string
    {
        if (! $this->cpf) {
            return null;
        }

        $d = preg_replace('/\D/', '', $this->cpf);

        return strlen($d) === 11 ? '***.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-**' : '***';
    }

    protected function rotuloAuditoria(): string
    {
        return 'Usuário '.$this->email;
    }
}
