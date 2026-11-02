<?php

namespace App\Models;

use App\Licitacao\TiposDocumento;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Peça da instrução. Depois de concluída não muda: uma alteração gera nova versão. */
#[Fillable(['orgao_id', 'processo_id', 'tipo', 'versao', 'conteudo', 'situacao', 'autor_id', 'origem', 'modelo_id', 'modelo_versao', 'arquivo_caminho', 'arquivo_nome', 'arquivo_tamanho', 'arquivo_hash', 'revisor_id', 'revisado_em', 'aprovador_id', 'aprovado_em', 'motivo_devolucao'])]
class DocumentoProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'documentos_processo';

    protected function casts(): array
    {
        return ['conteudo' => 'array', 'concluido_em' => 'datetime', 'revisado_em' => 'datetime', 'aprovado_em' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentoProcesso $d) {
            if ($d->getOriginal('situacao') === 'concluido') {
                throw new LogicException('Documento concluído não pode ser alterado. Crie uma nova versão.');
            }

            // Depois de enviado para revisão, aprovação ou assinatura, o conteúdo fica travado:
            // quem revisa, aprova ou assina precisa ver exatamente o que será assinado.
            if ($d->getOriginal('situacao') !== 'rascunho' && ($d->isDirty('conteudo') || $d->isDirty('arquivo_hash'))) {
                throw new LogicException('Documento em tramitação não pode ter o conteúdo alterado. Devolva-o para ajustes.');
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

    public function assinaturas(): HasMany
    {
        return $this->hasMany(Assinatura::class, 'documento_id')->orderBy('assinado_em');
    }

    public function modelo(): BelongsTo
    {
        return $this->belongsTo(ModeloDocumento::class, 'modelo_id');
    }

    /** Ordena as chaves: o jsonb do PostgreSQL não guarda a ordem, e o hash não pode depender dela. */
    private static function canonico(mixed $v): mixed
    {
        if (is_array($v)) {
            $v = array_map(self::canonico(...), $v);
            array_is_list($v) ? null : ksort($v);
        }

        return $v;
    }

    /** O que foi revisado, aprovado e assinado: o hash do texto ou o do arquivo enviado. */
    public function hashAtual(): string
    {
        return $this->origem === 'arquivo' && $this->arquivo_hash
            ? $this->arquivo_hash
            : hash('sha256', json_encode(self::canonico($this->conteudo ?? []), JSON_UNESCAPED_UNICODE));
    }

    public function concluir(): void
    {
        $this->situacao = 'concluido';
        $this->concluido_em = now();
        $this->hash_conteudo = $this->hashAtual();
        $this->save();
    }

    public function emTramitacao(): bool
    {
        return in_array($this->situacao, ['em_revisao', 'em_aprovacao', 'aguardando_assinatura'], true);
    }

    protected function rotuloAuditoria(): string
    {
        return "{$this->definicao()['rotulo']} v{$this->versao}";
    }
}
