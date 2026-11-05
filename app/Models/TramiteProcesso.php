<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimentação dos autos: remessa entre unidades, arquivamento ou desarquivamento. */
#[Fillable(['orgao_id', 'processo_id', 'tipo', 'unidade_origem_id', 'unidade_destino_id', 'despacho', 'enviado_por', 'enviado_em', 'recebido_por', 'recebido_em', 'cancelado_por', 'cancelado_em', 'motivo_cancelamento'])]
class TramiteProcesso extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'tramites_processo';

    public const TIPOS = ['remessa' => 'Remessa', 'arquivamento' => 'Arquivamento', 'desarquivamento' => 'Desarquivamento'];

    protected function casts(): array
    {
        return ['enviado_em' => 'datetime', 'recebido_em' => 'datetime', 'cancelado_em' => 'datetime'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(Unidade::class, 'unidade_origem_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Unidade::class, 'unidade_destino_id');
    }

    public function remetente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function recebedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recebido_por');
    }

    public function cancelador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por');
    }

    public function pendente(): bool
    {
        return $this->tipo === 'remessa' && $this->recebido_em === null && $this->cancelado_em === null;
    }

    public function situacao(): array
    {
        return match (true) {
            $this->tipo !== 'remessa' => [self::TIPOS[$this->tipo], 'b-mute'],
            $this->cancelado_em !== null => ['Remessa cancelada', 'b-mute'],
            $this->recebido_em !== null => ['Recebido', 'b-ok'],
            default => ['Aguardando recebimento', 'b-warn'],
        };
    }

    protected function rotuloAuditoria(): string
    {
        return 'Trâmite do processo';
    }
}
