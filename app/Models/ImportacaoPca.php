<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Importação de planilha de itens do PCA, com pré-visualização antes de gravar (art. 12, VII). */
#[Fillable(['orgao_id', 'plano_contratacao_id', 'user_id', 'arquivo_nome', 'arquivo_hash', 'situacao', 'linhas', 'total_linhas', 'validas', 'com_erro', 'duplicadas', 'importadas', 'confirmada_em'])]
class ImportacaoPca extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'importacoes_pca';

    public const SITUACOES = ['pre_visualizacao' => 'Aguardando confirmação', 'confirmada' => 'Importada', 'cancelada' => 'Cancelada'];

    protected function casts(): array
    {
        return ['linhas' => 'array', 'confirmada_em' => 'datetime'];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoContratacao::class, 'plano_contratacao_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pendente(): bool
    {
        return $this->situacao === 'pre_visualizacao';
    }

    /** As linhas lidas ficam fora da trilha: a trilha já registra cada item criado. */
    protected function camposSensiveisDoModelo(): array
    {
        return ['linhas'];
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Importação de planilha \"{$this->arquivo_nome}\" do PCA";
    }
}
