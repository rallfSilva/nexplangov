<?php

namespace App\Models;

use App\Licitacao\Enums\TipoTermo;
use App\Licitacao\Regras\VigenciaContratual;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['orgao_id', 'processo_id', 'fornecedor_id', 'gestor_id', 'fiscal_id', 'numero', 'ano', 'tipo', 'objeto', 'categoria', 'servico_continuo', 'reforma_equipamento', 'valor_inicial', 'valor_global', 'garantia_percentual', 'justificativa_garantia', 'assinatura', 'vigencia_inicio', 'vigencia_fim', 'fundamento_vigencia_especial', 'situacao', 'arquivo_caminho', 'arquivo_nome', 'arquivo_hash'])]
class Contrato extends Model
{
    use Auditable, BelongsToOrgao;

    /** Tabela 5.9 do PNCP (os mais usados). */
    public const TIPOS = [1 => 'Contrato', 5 => 'Termo de adesão', 7 => 'Empenho', 12 => 'Carta-contrato', 8 => 'Outros'];

    public const SITUACOES = ['vigente' => 'Vigente', 'encerrado' => 'Encerrado', 'rescindido' => 'Extinto'];

    protected function casts(): array
    {
        return [
            'servico_continuo' => 'boolean', 'reforma_equipamento' => 'boolean',
            'valor_inicial' => 'decimal:2', 'valor_global' => 'decimal:2', 'garantia_percentual' => 'decimal:2',
            'assinatura' => 'date', 'vigencia_inicio' => 'date', 'vigencia_fim' => 'date', 'tipo' => 'integer', 'categoria' => 'integer',
        ];
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function gestor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gestor_id');
    }

    public function fiscal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fiscal_id');
    }

    public function termos(): HasMany
    {
        return $this->hasMany(TermoContrato::class)->orderBy('assinatura');
    }

    public function reequilibrios(): HasMany
    {
        return $this->hasMany(Reequilibrio::class)->orderByDesc('solicitado_em');
    }

    public function medicoes(): HasMany
    {
        return $this->hasMany(Medicao::class)->orderBy('numero');
    }

    public function liquidacoes(): HasMany
    {
        return $this->hasMany(Liquidacao::class)->orderBy('id');
    }

    /** Teto para medir e pagar: valor inicial atualizado, mais acréscimos, menos supressões. */
    public function valorAtualizadoTotal(): float
    {
        return round($this->valorInicialAtualizado() + $this->totalAcrescimos() - $this->totalSupressoes(), 2);
    }

    public function rescisoes(): HasMany
    {
        return $this->hasMany(Rescisao::class)->orderByDesc('id');
    }

    public function enviosPncp(): MorphMany
    {
        return $this->morphMany(EnvioPncp::class, 'publicavel')->latest('id');
    }

    /** Valor inicial mais reajustes e repactuações (base do art. 125). */
    public function valorInicialAtualizado(): float
    {
        return round((float) $this->valor_inicial + (float) $this->termos()->where('tipo', TipoTermo::Apostilamento->value)->sum('valor_reajuste'), 2);
    }

    /** Acréscimos que contam para o limite do art. 125. Reequilíbrio (art. 124, II, d) é consensual e não entra. */
    public function totalAcrescimos(): float
    {
        return (float) $this->termos()->where('reequilibrio', false)->sum('valor_acrescimo');
    }

    public function totalSupressoes(): float
    {
        return (float) $this->termos()->sum('valor_supressao');
    }

    public function diasParaVencer(): int
    {
        return VigenciaContratual::diasParaVencer($this->vigencia_fim);
    }

    public function identificacao(): string
    {
        return "{$this->numero}/{$this->ano}";
    }

    protected function rotuloAuditoria(): string
    {
        return 'Contrato '.$this->identificacao();
    }
}
