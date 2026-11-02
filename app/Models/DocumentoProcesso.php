<?php

namespace App\Models;

use App\Licitacao\TiposDocumento;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Peça da instrução. Depois de concluída não muda: uma alteração gera nova versão. */
#[Fillable(['orgao_id', 'processo_id', 'tipo', 'versao', 'conteudo', 'situacao', 'autor_id'])]
class DocumentoProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'documentos_processo';

    protected function casts(): array
    {
        return ['conteudo' => 'array', 'concluido_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentoProcesso $d) {
            if ($d->getOriginal('situacao') === 'concluido') {
                throw new LogicException('Documento concluído não pode ser alterado. Crie uma nova versão.');
            }
        });
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function definicao(): array
    {
        return TiposDocumento::get($this->tipo);
    }

    public function concluir(): void
    {
        $this->situacao = 'concluido';
        $this->concluido_em = now();
        $this->hash_conteudo = hash('sha256', json_encode($this->conteudo, JSON_UNESCAPED_UNICODE));
        $this->save();
    }

    protected function rotuloAuditoria(): string
    {
        return "{$this->definicao()['rotulo']} v{$this->versao}";
    }
}
