<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Documento juntado aos autos. Não se altera nem se apaga; sai dos autos só por desentranhamento com motivo. */
#[Fillable(['orgao_id', 'processo_id', 'tipo', 'titulo', 'descricao', 'arquivo_caminho', 'arquivo_nome', 'arquivo_tamanho', 'arquivo_hash', 'mime', 'paginas', 'origem', 'autor_id', 'autor_externo', 'solicitacao_externa_id', 'juntado_em'])]
class JuntadaProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'juntadas_processo';

    public const TIPOS = [
        'certidao' => 'Certidão',
        'oficio' => 'Ofício ou memorando',
        'declaracao' => 'Declaração',
        'comprovante' => 'Comprovante',
        'proposta' => 'Proposta ou orçamento',
        'despacho' => 'Despacho',
        'documento_externo' => 'Documento enviado por externo',
        'outro' => 'Outro documento',
    ];

    public const MIMES = 'pdf,docx,odt,xlsx,ods,png,jpg,jpeg';

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Documento juntado não pode ser apagado; use o desentranhamento.'));
    }

    protected function casts(): array
    {
        return ['juntado_em' => 'datetime', 'desentranhado_em' => 'datetime', 'arquivo_tamanho' => 'integer', 'paginas' => 'integer'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function desentranhador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desentranhado_por');
    }

    public function ehImagem(): bool
    {
        return in_array(strtolower(pathinfo($this->arquivo_nome, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true);
    }

    public function quemJuntou(): string
    {
        return $this->autor?->name ?? ($this->autor_externo ? $this->autor_externo.' (externo)' : 'Usuário removido');
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Juntada {$this->titulo}";
    }
}
