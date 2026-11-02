<?php

namespace App\Models;

use App\Licitacao\Enums\AmparoLegal;
use App\Licitacao\Enums\CategoriaProcesso;
use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Enums\SituacaoProcesso;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Processo de contratação: licitação ou contratação direta. A situação só muda pela
 * App\Licitacao\TramitacaoProcesso, que aplica as regras da Lei 14.133.
 */
#[Fillable(['orgao_id', 'unidade_id', 'demanda_id', 'responsavel_id', 'fluxo_id', 'numero', 'ano', 'numero_compra', 'objeto', 'modalidade', 'amparo_legal', 'criterio_julgamento', 'modo_disputa', 'categoria', 'srp', 'orcamento_sigiloso', 'com_disputa', 'natureza_despesa', 'abertura_propostas_em', 'encerramento_propostas_em'])]
class Processo extends Model
{
    use Auditable, BelongsToOrgao;

    protected function casts(): array
    {
        return [
            'srp' => 'boolean', 'orcamento_sigiloso' => 'boolean', 'com_disputa' => 'boolean',
            'valor_estimado' => 'decimal:2', 'valor_homologado' => 'decimal:2',
            'abertura_propostas_em' => 'datetime', 'encerramento_propostas_em' => 'datetime',
            'modalidade' => 'integer', 'amparo_legal' => 'integer', 'criterio_julgamento' => 'integer', 'modo_disputa' => 'integer', 'categoria' => 'integer',
        ];
    }

    public function situacaoEnum(): SituacaoProcesso
    {
        return SituacaoProcesso::from($this->situacao);
    }

    public function modalidadeEnum(): Modalidade
    {
        return Modalidade::from($this->modalidade);
    }

    public function amparoEnum(): AmparoLegal
    {
        return AmparoLegal::from($this->amparo_legal);
    }

    public function categoriaEnum(): CategoriaProcesso
    {
        return CategoriaProcesso::from($this->categoria);
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function demanda(): BelongsTo
    {
        return $this->belongsTo(Demanda::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemProcesso::class)->orderBy('numero');
    }

    public function fluxo(): BelongsTo
    {
        return $this->belongsTo(Fluxo::class);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(EtapaProcesso::class)->orderBy('ordem');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoProcesso::class);
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoProcesso::class)->latest('id');
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class);
    }

    public function enviosPncp(): MorphMany
    {
        return $this->morphMany(EnvioPncp::class, 'publicavel')->latest('id');
    }

    /** Versão mais recente de uma peça da instrução. */
    public function documentoAtual(string $tipo): ?DocumentoProcesso
    {
        return $this->documentos()->where('tipo', $tipo)->orderByDesc('versao')->first();
    }

    public function documentoConcluido(string $tipo): bool
    {
        return $this->documentos()->where('tipo', $tipo)->where('situacao', 'concluido')->exists();
    }

    /** Recalcula o valor estimado do processo pela soma dos itens. */
    public function recalcularValor(): void
    {
        $this->forceFill(['valor_estimado' => round((float) $this->itens()->sum('valor_total_estimado'), 2)])->save();
    }

    public function editavel(): bool
    {
        return in_array($this->situacao, [SituacaoProcesso::Planejamento->value], true);
    }

    public function identificacao(): string
    {
        return "{$this->numero}/{$this->ano}";
    }

    protected function rotuloAuditoria(): string
    {
        return 'Processo '.$this->identificacao();
    }
}
