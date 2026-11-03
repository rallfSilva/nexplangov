<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Processo de responsabilização de licitante ou contratado (arts. 155 a 163). */
#[Fillable(['orgao_id', 'fornecedor_id', 'contrato_id', 'processo_id', 'numero', 'infracoes', 'fatos', 'sancao_proposta', 'justificativa_agravamento', 'comissao', 'situacao', 'instaurado_por'])]
class ProcessoSancionador extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'processos_sancionadores';

    public const SITUACOES = [
        'instaurado' => 'Instaurado · falta intimar',
        'defesa' => 'Prazo de defesa',
        'instrucao' => 'Produção de provas',
        'alegacoes' => 'Alegações finais',
        'decisao' => 'Pronto para decisão',
        'decidido' => 'Decidido · prazo de recurso',
        'recurso' => 'Em recurso',
        'definitivo' => 'Sanção definitiva',
        'arquivado' => 'Arquivado',
        'reabilitado' => 'Reabilitado',
    ];

    public const RESULTADOS_RECURSO = ['provido' => 'Provido (sanção afastada)', 'parcial' => 'Provido em parte', 'improvido' => 'Não provido'];

    protected function casts(): array
    {
        return [
            'infracoes' => 'array', 'comissao' => 'array', 'provas_requeridas' => 'boolean',
            'intimado_em' => 'date', 'prazo_defesa' => 'date', 'defesa_em' => 'date', 'prazo_alegacoes' => 'date', 'alegacoes_em' => 'date', 'parecer_em' => 'datetime',
            'base_calculo' => 'decimal:2', 'multa_valor' => 'decimal:2', 'decidido_em' => 'date', 'prazo_recurso' => 'date', 'prazo_ceis' => 'date',
            'recurso_em' => 'date', 'prazo_reconsideracao' => 'date', 'prazo_decisao_recurso' => 'date', 'recurso_decidido_em' => 'date',
            'definitivo_em' => 'date', 'ceis_informado_em' => 'date', 'reabilitado_em' => 'date',
        ];
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function sancao(): HasOne
    {
        return $this->hasOne(SancaoFornecedor::class);
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    /** Pendência do art. 161: sanção definitiva ainda não informada ao CEIS/CNEP. */
    public function ceisPendente(): bool
    {
        return in_array($this->situacao, ['definitivo', 'reabilitado'], true) && $this->ceis_informado_em === null;
    }

    public function ceisAtrasado(): bool
    {
        return $this->ceisPendente() && $this->prazo_ceis?->lt(today());
    }

    /** Cadastro de destino: CEIS para as sanções que impedem contratar; CNEP para as demais (confira a orientação vigente da CGU). */
    public function cadastroNacional(): string
    {
        return in_array($this->sancao_tipo, ['impedimento', 'inidoneidade'], true) ? 'CEIS' : 'CNEP';
    }

    protected function generoAuditoria(): string
    {
        return 'o';
    }

    protected function rotuloAuditoria(): string
    {
        return "Processo sancionador {$this->numero}";
    }
}
