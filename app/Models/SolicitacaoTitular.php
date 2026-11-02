<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Pedido do titular de dados (LGPD, art. 18), com prazo de resposta (art. 19, II). */
#[Fillable(['orgao_id', 'nome', 'email', 'cpf_cifrado', 'tipo', 'descricao', 'situacao', 'prazo_em', 'resposta'])]
#[Hidden(['cpf_cifrado'])]
class SolicitacaoTitular extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'solicitacoes_titular';

    public const TIPOS = [
        'confirmacao' => 'Confirmar se há tratamento dos meus dados (art. 18, I)',
        'acesso' => 'Acessar os meus dados (art. 18, II)',
        'correcao' => 'Corrigir dados incompletos, inexatos ou desatualizados (art. 18, III)',
        'anonimizacao' => 'Anonimizar, bloquear ou eliminar dados desnecessários (art. 18, IV)',
        'portabilidade' => 'Portabilidade dos dados (art. 18, V)',
        'compartilhamento' => 'Saber com quem os dados foram compartilhados (art. 18, VII)',
        'oposicao' => 'Opor-me a um tratamento (art. 18, § 2º)',
    ];

    public const SITUACOES = ['aberta' => 'Aberta', 'em_atendimento' => 'Em atendimento', 'concluida' => 'Concluída', 'indeferida' => 'Indeferida com justificativa'];

    protected function camposSensiveisDoModelo(): array
    {
        return ['cpf_cifrado'];
    }

    protected function casts(): array
    {
        return ['cpf_cifrado' => 'encrypted', 'prazo_em' => 'date', 'concluida_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (SolicitacaoTitular $s) {
            $s->protocolo ??= 'LGPD-'.now()->format('Y').'-'.Str::upper(Str::random(8));
            $s->prazo_em ??= today()->addDays((int) config('nexplangov.lgpd_prazo_dias', 15));
        });
    }

    public function atendente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendido_por');
    }

    public function atrasada(): bool
    {
        return ! in_array($this->situacao, ['concluida', 'indeferida'], true) && $this->prazo_em->isPast() && ! $this->prazo_em->isToday();
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return 'Solicitação LGPD '.$this->protocolo;
    }
}
