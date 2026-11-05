<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\Regras\LivroOcorrencias;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Models\OcorrenciaContrato;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Registro no livro de ocorrências e designação formal de gestor e fiscal (art. 117). */
final class LivroDeOcorrencias
{
    /** @param array{tipo: string, ocorrida_em: string, descricao: string, providencias?: ?string, prazo_regularizacao?: ?string, retifica_id?: ?int} $d */
    public function registrar(Contrato $c, User $u, array $d): OcorrenciaContrato
    {
        $papel = match ((int) $u->id) {
            (int) $c->fiscal_id => 'fiscal',
            (int) $c->gestor_id => 'gestor',
            default => null,
        };
        $retifica = isset($d['retifica_id']) ? $c->ocorrencias()->whereKey($d['retifica_id'])->first() : null;
        $prazo = filled($d['prazo_regularizacao'] ?? null) ? CarbonImmutable::parse($d['prazo_regularizacao']) : null;

        $m = LivroOcorrencias::validar($d['tipo'], (string) $d['descricao'], CarbonImmutable::parse($d['ocorrida_em']), today(), $c->vigencia_inicio, $papel !== null, $retifica !== null, $prazo);
        if ($m) {
            throw new RegraViolada($m);
        }

        return DB::transaction(function () use ($c, $u, $d, $papel, $retifica, $prazo) {
            Contrato::whereKey($c->id)->lockForUpdate()->first(); // numeração e encadeamento sem corrida
            $anterior = OcorrenciaContrato::where('contrato_id', $c->id)->orderByDesc('numero')->first();

            $o = new OcorrenciaContrato([
                'orgao_id' => $c->orgao_id, 'contrato_id' => $c->id, 'numero' => ($anterior?->numero ?? 0) + 1, 'user_id' => $u->id,
                'autor_nome' => $u->name, 'autor_papel' => $papel, 'tipo' => $d['tipo'], 'ocorrida_em' => $d['ocorrida_em'],
                'descricao' => trim($d['descricao']), 'providencias' => filled($d['providencias'] ?? null) ? trim($d['providencias']) : null,
                'prazo_regularizacao' => $d['tipo'] === 'determinacao' ? $prazo?->toDateString() : null,
                'retifica_id' => $d['tipo'] === 'retificacao' ? $retifica?->id : null, 'registrada_em' => now()->startOfSecond(),
                'hash_anterior' => $anterior?->hash,
            ]);
            $o->hash = LivroOcorrencias::hash($o->camposDoHash(), $anterior?->hash);
            $o->save();

            return $o;
        });
    }

    /** Confere o encadeamento: true se nenhuma anotação foi mexida no banco. */
    public function integro(Contrato $c): bool
    {
        $anterior = null;

        foreach ($c->ocorrencias()->reorder('numero')->get() as $o) {
            if ($o->hash_anterior !== $anterior || $o->hash !== LivroOcorrencias::hash($o->camposDoHash(), $anterior)) {
                return false;
            }
            $anterior = $o->hash;
        }

        return true;
    }

    /** Designação formal de gestor e fiscal pela autoridade (art. 117, caput; segregação, art. 7º, § 1º). */
    public function designar(Contrato $c, User $autoridade, string $ato, string $data): void
    {
        $m = [];
        if (! $c->gestor_id || ! $c->fiscal_id) {
            $m[] = 'Defina o gestor e o fiscal do contrato antes de designá-los.';
        }
        if (mb_strlen(trim($ato)) < 3) {
            $m[] = 'Informe o ato de designação (ex.: Portaria nº 12/2026).';
        }
        if ($data > today()->toDateString()) {
            $m[] = 'A data da designação não pode ser futura.';
        }
        if (in_array((int) $autoridade->id, [(int) $c->gestor_id, (int) $c->fiscal_id], true)) {
            $m[] = 'Quem designa não pode ser o próprio gestor ou fiscal (segregação de funções, art. 7º, § 1º).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        $c->forceFill(['designacao_ato' => trim($ato), 'designacao_em' => $data, 'designado_por' => $autoridade->id])->save();
    }
}
