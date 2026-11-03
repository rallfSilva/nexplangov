<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de adesão (carona) de órgão não participante a um item da ata (art. 86, §§ 2º a 8º). */
#[Fillable(['orgao_id', 'ata_item_id', 'aderente_nome', 'aderente_cnpj', 'quantidade', 'justificativa', 'vantagem', 'situacao', 'motivo_decisao', 'decidida_por', 'decidida_em', 'solicitada_por',
    'protocolo', 'origem', 'aderente_esfera', 'aderente_uf', 'aderente_municipio', 'solicitante_nome', 'solicitante_cargo', 'solicitante_email', 'oficio_caminho', 'oficio_hash',
    'fornecedor_aceite', 'fornecedor_aceite_em', 'fornecedor_aceite_representante', 'fornecedor_aceite_obs', 'fornecedor_aceite_via', 'fornecedor_aceite_caminho', 'fornecedor_aceite_registrado_por',
    'prazo_contratacao', 'codigo_verificacao', 'autorizacao_caminho', 'autorizacao_hash'])]
class AtaAdesao extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'ata_adesoes';

    public const ESFERAS = ['municipal' => 'Municipal', 'estadual' => 'Estadual', 'distrital' => 'Distrital', 'federal' => 'Federal'];

    protected function casts(): array
    {
        return ['quantidade' => 'decimal:4', 'decidida_em' => 'datetime', 'fornecedor_aceite_em' => 'datetime', 'prazo_contratacao' => 'date'];
    }

    public function ataItem(): BelongsTo
    {
        return $this->belongsTo(AtaItem::class);
    }

    public function decisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por');
    }

    /** Situação para exibir: a autorizada vira "prazo encerrado" quando passa o prazo para contratar. */
    public function situacaoExibida(): string
    {
        if ($this->situacao === 'autorizada' && $this->prazoEncerrado()) {
            return 'Prazo para contratar encerrado';
        }

        return match ($this->situacao) {
            'solicitada' => match ($this->fornecedor_aceite) {
                'aceito' => 'Fornecedor aceitou · aguardando o gerenciador',
                'recusado' => 'Fornecedor recusou',
                default => 'Aguardando aceite do fornecedor',
            },
            'autorizada' => 'Autorizada',
            'negada' => 'Negada',
            default => $this->situacao,
        };
    }

    public function prazoEncerrado(): bool
    {
        return $this->prazo_contratacao !== null && $this->prazo_contratacao->lt(today());
    }

    public function consumido(): float
    {
        return (float) AtaConsumo::where('adesao_id', $this->id)->sum('quantidade');
    }

    /** Dados de contato do servidor solicitante ficam fora da trilha de auditoria. */
    protected function camposSensiveisDoModelo(): array
    {
        return ['solicitante_email'];
    }

    protected function rotuloAuditoria(): string
    {
        return "Adesão de {$this->aderente_nome} à ata";
    }
}
