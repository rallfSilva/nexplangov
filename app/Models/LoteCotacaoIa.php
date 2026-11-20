<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de cotação automática por IA de um processo: quais itens, quantos preços por item e o andamento na fila. */
#[Fillable(['orgao_id', 'processo_id', 'user_id', 'escopo', 'resultados', 'situacao', 'total_itens', 'itens_processados', 'itens_cotados', 'concluido_em'])]
class LoteCotacaoIa extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'lotes_cotacao_ia';

    public const ESCOPOS = ['sem_preco' => 'Só os itens sem preço', 'todos' => 'Todos os itens'];

    public const RESULTADOS = [3, 5, 10];

    protected function casts(): array
    {
        return ['resultados' => 'integer', 'total_itens' => 'integer', 'itens_processados' => 'integer', 'itens_cotados' => 'integer', 'concluido_em' => 'datetime'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function emAndamento(): bool
    {
        return $this->situacao !== 'concluido';
    }

    public function percentual(): int
    {
        return $this->total_itens > 0 ? (int) floor($this->itens_processados / $this->total_itens * 100) : 100;
    }

    protected function generoAuditoria(): string
    {
        return 'a';
    }

    protected function rotuloAuditoria(): string
    {
        return "Cotação por IA do processo #{$this->processo_id}";
    }
}
