<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use App\Support\Documento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor. CNPJ é dado público e fica aberto. CPF de pessoa física é dado pessoal (LGPD):
 * fica cifrado, a tela só mostra a versão mascarada e a busca usa um HMAC.
 */
#[Fillable(['orgao_id', 'tipo_pessoa', 'documento', 'razao_social', 'nome_fantasia', 'porte', 'email', 'telefone', 'municipio', 'uf', 'ativo'])]
#[Hidden(['documento_cifrado', 'documento_hash'])]
class Fornecedor extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'fornecedores';

    public const PORTES = ['ME' => 'Microempresa', 'EPP' => 'Empresa de pequeno porte', 'DEMAIS' => 'Demais'];

    protected function camposSensiveisDoModelo(): array
    {
        return ['documento_cifrado', 'documento_hash'];
    }

    protected function casts(): array
    {
        return ['ativo' => 'boolean', 'documento_cifrado' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::saving(function (Fornecedor $f) {
            $digitos = Documento::digitos((string) ($f->documento_cifrado ?: $f->documento));

            if ($f->tipo_pessoa === 'PF' && strlen($digitos) === 11) {
                $f->documento_cifrado = $digitos;
                $f->documento = Documento::mascararCpf($digitos);
            } elseif (strlen($digitos) === 14) {
                $f->documento = Documento::formatarCnpj($digitos);
            }

            $f->documento_hash = Documento::hash($digitos);
        });
    }

    public function sancoes(): HasMany
    {
        return $this->hasMany(SancaoFornecedor::class);
    }

    /** Impedimento ou inidoneidade vigente impedem contratar (art. 156, III e IV, e art. 14, III). */
    public function impedidoEm(?\DateTimeInterface $data = null): ?SancaoFornecedor
    {
        $data ??= today();

        return $this->sancoes()
            ->whereIn('tipo', ['impedimento', 'inidoneidade'])
            ->whereDate('inicio', '<=', $data)
            ->where(fn ($q) => $q->whereNull('fim')->orWhereDate('fim', '>=', $data))
            ->first();
    }

    /** Documento completo, só para envio ao PNCP e para quem tem permissão. */
    public function documentoCompleto(): string
    {
        return $this->tipo_pessoa === 'PF' ? (string) $this->documento_cifrado : Documento::digitos($this->documento);
    }

    protected function rotuloAuditoria(): string
    {
        return 'Fornecedor '.$this->razao_social;
    }
}
