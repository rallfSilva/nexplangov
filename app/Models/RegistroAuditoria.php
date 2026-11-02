<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * Registro da trilha de auditoria. Somente inclusão: não pode ser alterado nem apagado
 * (bloqueado aqui e por trigger no PostgreSQL).
 */
#[Fillable(['orgao_id', 'user_id', 'evento', 'auditable_type', 'auditable_id', 'descricao', 'valores_antigos', 'valores_novos', 'ip', 'user_agent', 'created_at', 'hash_anterior', 'hash'])]
class RegistroAuditoria extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'registros_auditoria';

    /** @var array<string, string> */
    public const EVENTOS = [
        'login' => 'Entrada no sistema',
        'logout' => 'Saída do sistema',
        'login_falhou' => 'Tentativa de entrada recusada',
        'criado' => 'Criação',
        'alterado' => 'Alteração',
        'excluido' => 'Exclusão',
        'permissao' => 'Mudança de perfil',
        'senha' => 'Senha',
        'seguranca' => 'Verificação em duas etapas',
        'download' => 'Download',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('orgao', function (Builder $query) {
            if (Auth::hasUser() && Auth::user()->orgao_id !== null) {
                $query->where('orgao_id', Auth::user()->orgao_id);
            }
        });

        static::updating(fn () => throw new LogicException('A trilha de auditoria não pode ser alterada.'));
        static::deleting(fn () => throw new LogicException('A trilha de auditoria não pode ser apagada.'));
    }

    protected function casts(): array
    {
        return [
            'valores_antigos' => 'array',
            'valores_novos' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function orgao(): BelongsTo
    {
        return $this->belongsTo(Orgao::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function rotuloEvento(): string
    {
        return self::EVENTOS[$this->evento] ?? $this->evento;
    }
}
