<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Registro das operações de tratamento de dados pessoais (LGPD, art. 37). */
#[Fillable(['orgao_id', 'nome', 'finalidade', 'base_legal', 'dados_tratados', 'titulares', 'compartilhamento', 'retencao', 'medidas_seguranca'])]
class OperacaoTratamento extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'operacoes_tratamento';

    public const BASES_LEGAIS = [
        'art7_II' => 'Cumprimento de obrigação legal (art. 7º, II)',
        'art7_III' => 'Execução de políticas públicas (art. 7º, III, e art. 23)',
        'art7_V' => 'Execução de contrato (art. 7º, V)',
        'art7_VI' => 'Exercício regular de direitos em processo (art. 7º, VI)',
        'art7_I' => 'Consentimento do titular (art. 7º, I)',
    ];

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return 'Operação de tratamento '.$this->nome;
    }
}
