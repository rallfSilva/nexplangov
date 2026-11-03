<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Extinção do contrato (arts. 137 a 139), sempre motivada, com contraditório e ampla defesa. */
#[Fillable(['orgao_id', 'contrato_id', 'modalidade', 'motivo', 'fundamentacao', 'notificado_em', 'defesa_ate', 'defesa', 'defesa_recebida_em', 'data_efeito', 'culpa_administracao', 'valor_indenizacao', 'situacao', 'termo_id', 'iniciada_por', 'concluida_por', 'concluida_em'])]
class Rescisao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'rescisoes';

    public const MODALIDADES = ['unilateral' => 'Ato unilateral da Administração', 'consensual' => 'Acordo entre as partes', 'judicial' => 'Decisão arbitral ou judicial'];

    public const MOTIVOS = [
        'descumprimento' => 'Descumprimento ou cumprimento irregular de cláusulas, especificações ou prazos',
        'desatendimento_fiscalizacao' => 'Desatendimento das determinações da fiscalização',
        'alteracao_empresa' => 'Alteração social ou da finalidade da empresa que restrinja a execução',
        'falencia_insolvencia' => 'Falência, insolvência, dissolução da sociedade ou falecimento do contratado',
        'caso_fortuito_forca_maior' => 'Caso fortuito ou força maior que impeça a execução',
        'atraso_pagamento' => 'Atraso nos pagamentos devidos pela Administração',
        'interesse_publico' => 'Razões de interesse público justificadas pela autoridade máxima',
        'reserva_cargos' => 'Descumprimento da reserva de cargos prevista em lei',
    ];

    protected function casts(): array
    {
        return [
            'notificado_em' => 'date', 'defesa_ate' => 'date', 'defesa_recebida_em' => 'date', 'data_efeito' => 'date', 'concluida_em' => 'datetime',
            'culpa_administracao' => 'boolean', 'valor_indenizacao' => 'decimal:2',
        ];
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }

    protected function rotuloAuditoria(): string
    {
        return "Rescisão do contrato #{$this->contrato_id}";
    }
}
