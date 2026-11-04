<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pacote com todos os dados e arquivos do órgão, para devolução ao fim do contrato. */
#[Fillable(['orgao_id', 'user_id', 'situacao', 'arquivo_caminho', 'tamanho', 'sha256', 'resumo', 'erro', 'expira_em', 'baixado_em'])]
class PacoteDevolucao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'pacotes_devolucao';

    public const SITUACOES = ['na_fila' => ['Na fila', 'b-info'], 'gerando' => ['Gerando', 'b-info'], 'pronto' => ['Pronto', 'b-ok'], 'erro' => ['Com erro', 'b-err'], 'expirado' => ['Expirado', 'b-mute']];

    /** Dias que o arquivo fica disponível para baixar. */
    public const DIAS_DISPONIVEL = 7;

    protected function casts(): array
    {
        return ['resumo' => 'array', 'expira_em' => 'datetime', 'baixado_em' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function disponivel(): bool
    {
        return $this->situacao === 'pronto' && $this->expira_em?->isFuture();
    }

    protected function camposSensiveisDoModelo(): array
    {
        return ['resumo'];
    }

    protected function rotuloAuditoria(): string
    {
        return 'Pacote de devolução de dados #'.$this->getKey();
    }
}
