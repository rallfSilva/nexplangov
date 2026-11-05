<?php

namespace App\Integracoes\Transparencia;

use App\Models\ConsultaImpedimento;
use App\Models\Fornecedor;
use App\Models\User;

/**
 * Antes de adjudicar, contratar ou emitir ordem de fornecimento, confere o fornecedor no CEIS e no CNEP
 * (art. 161 e art. 156). Reaproveita a consulta recente; se ela for antiga, consulta de novo.
 *
 * Sem chave da API configurada, a verificação fica desligada. Se a API estiver fora do ar, a operação segue
 * (não se para a Administração por falha externa), mas a consulta indisponível fica registrada.
 */
final class VerificadorImpedimento
{
    public function __construct(private ConsultaCeisCnep $consulta) {}

    /** @return list<string> motivos que impedem contratar; vazio libera */
    public function motivos(Fornecedor $f, ?User $u = null): array
    {
        if (! $this->consulta->habilitada()) {
            return [];
        }

        return $this->consultaRecente($f, $u)->motivos();
    }

    public function consultaRecente(Fornecedor $f, ?User $u = null): ConsultaImpedimento
    {
        $ultima = ConsultaImpedimento::where('fornecedor_id', $f->id)->where('situacao', '!=', 'indisponivel')->latest('consultado_em')->latest('id')->first();
        $validade = now()->subHours((int) config('nexplangov.transparencia.validade_horas', 24));

        return $ultima && $ultima->consultado_em->greaterThan($validade) ? $ultima : $this->consulta->consultar($f, $u);
    }
}
