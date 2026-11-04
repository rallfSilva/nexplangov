<?php

namespace App\Models;

use App\Licitacao\Regras\VersoesPca;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plano de Contratações Anual (art. 12, VII). Depois de aprovado, só muda por revisão registrada. */
#[Fillable(['orgao_id', 'ano', 'situacao', 'observacoes'])]
class PlanoContratacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'planos_contratacao';

    public const SITUACOES = ['rascunho' => 'Em elaboração', 'em_aprovacao' => 'Em aprovação', 'aprovado' => 'Aprovado'];

    protected function casts(): array
    {
        return ['aprovado_em' => 'datetime', 'revisao_aberta_em' => 'datetime', 'versao_atual' => 'integer'];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemPca::class)->orderBy('numero');
    }

    public function aprovador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprovado_por');
    }

    public function editavel(): bool
    {
        return $this->situacao !== 'aprovado';
    }

    public function importacoes(): HasMany
    {
        return $this->hasMany(ImportacaoPca::class)->latest();
    }

    public function versoes(): HasMany
    {
        return $this->hasMany(VersaoPca::class)->orderByDesc('numero');
    }

    public function versaoVigente(): ?VersaoPca
    {
        return $this->versao_atual > 0 ? $this->versoes()->where('numero', $this->versao_atual)->first() : null;
    }

    public function autorRevisao(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisao_aberta_por');
    }

    /** Revisão aberta sobre um plano já aprovado: os itens podem mudar até a nova aprovação. */
    public function emRevisao(): bool
    {
        return $this->revisao_aberta_em !== null;
    }

    /** Próximo número de item: nunca reaproveita número já usado no plano ou em versão anterior. */
    public function proximoNumeroItem(): int
    {
        $usados = $this->itens()->pluck('numero')->all();
        foreach ($this->versoes()->get(['itens']) as $v) {
            $usados = [...$usados, ...array_column($v->itens, 'numero')];
        }

        return VersoesPca::proximoNumero(array_map('intval', $usados));
    }

    /** @return list<array<string, mixed>> cópia dos itens atuais, com os campos guardados na versão */
    public function copiaDosItens(): array
    {
        return $this->itens()->get()->map(function (ItemPca $i) {
            $linha = [];
            foreach (VersaoPca::CAMPOS_ITEM as $c) {
                $v = $i->getAttribute($c);
                $linha[$c] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
            }

            return $linha;
        })->values()->all();
    }

    public function valorTotal(): float
    {
        return (float) $this->itens()->sum('valor_total_estimado');
    }

    protected function rotuloAuditoria(): string
    {
        return 'PCA '.$this->ano;
    }
}
