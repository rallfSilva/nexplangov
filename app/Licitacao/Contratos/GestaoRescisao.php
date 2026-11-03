<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\Enums\TipoTermo;
use App\Licitacao\RegraViolada;
use App\Models\Contrato;
use App\Models\Rescisao;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Extinção do contrato (arts. 137 a 139): formalmente motivada nos autos, com contraditório e ampla
 * defesa. A rescisão unilateral só se conclui depois da defesa ou do fim do prazo dado ao contratado.
 */
final class GestaoRescisao
{
    public function iniciar(Contrato $c, string $modalidade, string $motivo, string $fundamentacao, ?string $notificadoEm, ?string $defesaAte, User $u): Rescisao
    {
        $m = [];

        if ($c->situacao !== 'vigente') {
            $m[] = 'O contrato não está vigente.';
        }
        if ($c->rescisoes()->where('situacao', 'iniciada')->exists()) {
            $m[] = 'Já existe uma extinção em andamento para este contrato.';
        }
        if (! array_key_exists($modalidade, Rescisao::MODALIDADES)) {
            $m[] = 'Modalidade inválida (art. 138).';
        }
        if (! array_key_exists($motivo, Rescisao::MOTIVOS)) {
            $m[] = 'Motivo inválido (art. 137).';
        }
        if (mb_strlen(trim($fundamentacao)) < 30) {
            $m[] = 'A extinção deve ser formalmente motivada nos autos (mínimo 30 caracteres, art. 137).';
        }
        if ($modalidade === 'unilateral') {
            if (! $notificadoEm || ! $defesaAte) {
                $m[] = 'Na rescisão unilateral, informe a data da comunicação formal ao contratado e o prazo para a defesa (art. 138, § 1º, e contraditório do art. 137).';
            } elseif ($defesaAte <= $notificadoEm) {
                $m[] = 'O prazo da defesa precisa terminar depois da comunicação.';
            }
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        return Rescisao::create([
            'orgao_id' => $c->orgao_id, 'contrato_id' => $c->id, 'modalidade' => $modalidade, 'motivo' => $motivo, 'fundamentacao' => trim($fundamentacao),
            'notificado_em' => $modalidade === 'unilateral' ? $notificadoEm : null, 'defesa_ate' => $modalidade === 'unilateral' ? $defesaAte : null, 'iniciada_por' => $u->id,
        ]);
    }

    public function registrarDefesa(Rescisao $r, string $defesa): void
    {
        if ($r->situacao !== 'iniciada' || $r->modalidade !== 'unilateral') {
            throw new RegraViolada(['A defesa vale na rescisão unilateral em andamento.']);
        }
        if (mb_strlen(trim($defesa)) < 10) {
            throw new RegraViolada(['Registre o resumo da defesa (mínimo 10 caracteres).']);
        }
        $r->update(['defesa' => trim($defesa), 'defesa_recebida_em' => today()]);
    }

    public function concluir(Rescisao $r, string $dataEfeito, bool $culpaAdministracao, ?float $indenizacao, User $u): void
    {
        $m = [];
        $c = $r->contrato;

        if ($r->situacao !== 'iniciada') {
            $m[] = 'Esta extinção não está em andamento.';
        }
        if ($r->modalidade === 'unilateral' && $r->defesa_recebida_em === null && $r->defesa_ate?->gte(today())) {
            $m[] = 'Aguarde a defesa do contratado ou o fim do prazo ('.$r->defesa_ate->format('d/m/Y').'): contraditório e ampla defesa (art. 137).';
        }
        if ($dataEfeito < $c->assinatura->format('Y-m-d')) {
            $m[] = 'A data de efeito não pode ser anterior à assinatura do contrato.';
        }
        if ($culpaAdministracao && $indenizacao === null) {
            $m[] = 'Quando a culpa é exclusiva da Administração, informe o ressarcimento devido ao contratado, mesmo que seja zero (art. 138, § 2º).';
        }
        if ($m) {
            throw new RegraViolada($m);
        }

        DB::transaction(function () use ($r, $c, $dataEfeito, $culpaAdministracao, $indenizacao, $u) {
            $termo = $c->termos()->create([
                'orgao_id' => $c->orgao_id, 'tipo' => TipoTermo::Rescisao->value, 'numero' => 'RESC-'.$r->id, 'assinatura' => today(),
                'valor_acrescimo' => 0, 'valor_supressao' => 0, 'valor_reajuste' => 0,
                'justificativa' => 'Extinção ('.Rescisao::MODALIDADES[$r->modalidade].'): '.$r->fundamentacao,
            ]);
            $c->forceFill(['situacao' => 'rescindido'])->save();
            $r->update([
                'situacao' => 'concluida', 'data_efeito' => $dataEfeito, 'culpa_administracao' => $culpaAdministracao, 'valor_indenizacao' => $indenizacao,
                'termo_id' => $termo->id, 'concluida_por' => $u->id, 'concluida_em' => now(),
            ]);
        });
    }

    public function cancelar(Rescisao $r): void
    {
        if ($r->situacao !== 'iniciada') {
            throw new RegraViolada(['Só se cancela extinção em andamento.']);
        }
        $r->update(['situacao' => 'cancelada']);
    }
}
