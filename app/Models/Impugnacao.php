<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Impugnação ao edital ou pedido de esclarecimento (art. 164). O e-mail de quem pediu fica cifrado. */
#[Fillable(['orgao_id', 'processo_id', 'protocolo', 'tipo', 'canal', 'autor_nome', 'autor_email', 'texto', 'recebida_em', 'limite_protocolo', 'tempestiva', 'prazo_resposta_em', 'situacao', 'resultado', 'resposta', 'respondida_por', 'respondida_em'])]
#[Hidden(['autor_email'])]
class Impugnacao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'impugnacoes';

    public const TIPOS = ['impugnacao' => 'Impugnação ao edital', 'esclarecimento' => 'Pedido de esclarecimento'];

    public const RESULTADOS = ['procedente' => 'Procedente', 'parcial' => 'Procedente em parte', 'improcedente' => 'Improcedente'];

    protected function camposSensiveisDoModelo(): array
    {
        return ['autor_email'];
    }

    protected function casts(): array
    {
        return [
            'autor_email' => 'encrypted', 'recebida_em' => 'datetime', 'respondida_em' => 'datetime',
            'limite_protocolo' => 'date', 'prazo_resposta_em' => 'date', 'tempestiva' => 'boolean',
        ];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function atrasada(): bool
    {
        return $this->situacao === 'recebida' && $this->prazo_resposta_em !== null && $this->prazo_resposta_em->lt(today());
    }

    protected function rotuloAuditoria(): string
    {
        return "{$this->protocolo} ({$this->tipo})";
    }
}
