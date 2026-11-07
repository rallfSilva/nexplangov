<?php

namespace App\Models;

use App\Licitacao\Regras\PedidoExterno;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pedido por e-mail a pessoa de fora do órgão: notificação, assinatura de peça ou envio de documentos. */
#[Fillable(['orgao_id', 'processo_id', 'documento_id', 'tipo', 'nome', 'email', 'mensagem', 'token_hash', 'prazo_em', 'criado_por', 'enviado_em'])]
#[Hidden(['token_hash'])]
class SolicitacaoExterna extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'solicitacoes_externas';

    protected function casts(): array
    {
        return ['prazo_em' => 'date', 'enviado_em' => 'datetime', 'respondida_em' => 'datetime', 'cancelada_em' => 'datetime'];
    }

    public function processo(): BelongsTo
    {
        return $this->belongsTo(Processo::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(DocumentoProcesso::class, 'documento_id');
    }

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function juntadas(): HasMany
    {
        return $this->hasMany(JuntadaProcesso::class, 'solicitacao_externa_id');
    }

    public static function porToken(string $token): ?self
    {
        return self::where('token_hash', hash('sha256', $token))->first();
    }

    public function pendente(): bool
    {
        return $this->respondida_em === null && $this->cancelada_em === null;
    }

    public function rotuloTipo(): string
    {
        return PedidoExterno::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** @return array{0: string, 1: string} */
    public function situacao(): array
    {
        return match (true) {
            $this->cancelada_em !== null => ['Cancelado', 'b-mute'],
            $this->respondida_em !== null => [$this->tipo === 'notificacao' ? 'Ciente' : ($this->tipo === 'assinatura' ? 'Assinado' : 'Documentos recebidos'), 'b-ok'],
            today()->greaterThan($this->prazo_em) => ['Prazo vencido', 'b-err'],
            default => ['Aguardando resposta', 'b-warn'],
        };
    }

    protected function camposSensiveisDoModelo(): array
    {
        return ['token_hash', 'email'];
    }

    protected function rotuloAuditoria(): string
    {
        return "Pedido externo a {$this->nome}";
    }
}
