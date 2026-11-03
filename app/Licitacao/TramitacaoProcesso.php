<?php

namespace App\Licitacao;

use App\Licitacao\Enums\AmparoLegal;
use App\Licitacao\Enums\Modalidade;
use App\Licitacao\Enums\SituacaoProcesso as S;
use App\Licitacao\Regras\InstrucaoProcesso;
use App\Licitacao\Regras\LimiteDispensa;
use App\Models\EventoProcesso;
use App\Models\Processo;
use App\Models\User;
use App\Pncp\FilaPncp;
use Illuminate\Support\Facades\DB;

/**
 * Máquina de estados do processo de contratação. Cada passagem exige a permissão do perfil
 * responsável, checa as regras da Lei 14.133 e fica registrada em eventos_processo e na auditoria.
 */
final class TramitacaoProcesso
{
    public function __construct(
        private InstrucaoProcesso $instrucao,
        private LimiteDispensa $limiteDispensa,
        private FilaPncp $fila,
    ) {}

    /**
     * Destinos possíveis a partir de cada situação, com a permissão exigida e se pede justificativa.
     *
     * @return array<string, array{permissao: string, justificativa: bool, rotulo: string}>
     */
    public function destinos(Processo $processo): array
    {
        $direta = Modalidade::from($processo->modalidade)->ehContratacaoDireta();
        $atual = $processo->situacaoEnum();

        $mapa = match ($atual) {
            S::Planejamento => [
                S::AnaliseJuridica->value => ['processos.gerenciar', false, 'Enviar para análise jurídica'],
            ],
            S::AnaliseJuridica => [
                S::AguardandoAutorizacao->value => ['processos.parecer', false, 'Concluir análise e enviar à autoridade'],
                S::Planejamento->value => ['processos.parecer', true, 'Devolver para ajustes'],
            ],
            S::AguardandoAutorizacao => [
                S::Publicado->value => ['processos.autorizar', false, $direta ? 'Autorizar a contratação direta e divulgar' : 'Autorizar e publicar o edital'],
                S::Planejamento->value => ['processos.autorizar', true, 'Devolver para ajustes'],
            ],
            S::Publicado => $direta
                ? [S::Homologado->value => ['processos.autorizar', false, 'Registrar a contratação direta']]
                : [S::EmJulgamento->value => ['processos.gerenciar', false, 'Abrir a sessão e o julgamento']],
            S::EmJulgamento => [
                S::Homologado->value => ['processos.autorizar', false, 'Homologar o resultado'],
                S::Deserto->value => ['processos.gerenciar', true, 'Declarar deserto'],
                S::Fracassado->value => ['processos.gerenciar', true, 'Declarar fracassado'],
            ],
            S::Homologado => [
                S::Contratado->value => ['processos.gerenciar', false, 'Marcar como contratado'],
            ],
            default => [],
        };

        if (! $atual->encerrado() && $atual !== S::Homologado) {
            $mapa[S::Revogado->value] = ['processos.autorizar', true, 'Revogar (art. 71, II)'];
            $mapa[S::Anulado->value] = ['processos.autorizar', true, 'Anular (art. 71, III)'];
        }

        return collect($mapa)->map(fn ($d) => ['permissao' => $d[0], 'justificativa' => $d[1], 'rotulo' => $d[2]])->all();
    }

    /** @return array<string, array{permissao: string, justificativa: bool, rotulo: string}> */
    public function destinosPara(Processo $processo, User $usuario): array
    {
        return array_filter($this->destinos($processo), fn ($d) => $usuario->can($d['permissao']));
    }

    public function transitar(Processo $processo, string $destino, User $usuario, ?string $justificativa = null): Processo
    {
        $destinos = $this->destinos($processo);

        if (! isset($destinos[$destino])) {
            throw new RegraViolada(["Não é possível passar de \"{$processo->situacaoEnum()->rotulo()}\" para \"".S::from($destino)->rotulo().'".']);
        }

        $regra = $destinos[$destino];

        if (! $usuario->can($regra['permissao'])) {
            throw new RegraViolada(['Seu perfil não pode fazer esta passagem.']);
        }

        $justificativa = trim((string) $justificativa);

        if ($regra['justificativa'] && mb_strlen($justificativa) < 20) {
            throw new RegraViolada(['Escreva uma justificativa com pelo menos 20 caracteres. Ela fica no processo e é pública.']);
        }

        $this->validarRegras($processo, S::from($destino));

        return DB::transaction(function () use ($processo, $destino, $usuario, $justificativa) {
            $de = $processo->situacao;

            if ($destino === S::Homologado->value) {
                $processo->valor_homologado = $processo->itens()
                    ->where('situacao', 'homologado')
                    ->get()
                    ->sum(fn ($i) => round((float) ($i->valor_unitario_homologado ?? $i->valor_unitario_estimado) * (float) $i->quantidade, 2));

                $processo->itens()->where('situacao', 'pendente')->update(['situacao' => 'homologado']);
            }

            $processo->situacao = $destino;
            $processo->save();

            EventoProcesso::create([
                'orgao_id' => $processo->orgao_id,
                'processo_id' => $processo->id,
                'user_id' => $usuario->id,
                'de' => $de,
                'para' => $destino,
                'justificativa' => $justificativa ?: null,
            ]);

            $this->publicarNoPncp($processo, S::from($destino), $usuario);

            return $processo->refresh();
        });
    }

    private function validarRegras(Processo $processo, S $destino): void
    {
        $motivos = [];

        if ($destino === S::AnaliseJuridica) {
            foreach ($this->instrucao->pendenciasInstrucao($processo) as $p) {
                $motivos[] = "Falta: {$p['rotulo']} ({$p['base']}).".($p['detalhe'] ? " {$p['detalhe']}" : '');
            }

            $amparo = AmparoLegal::from($processo->amparo_legal);

            if ($amparo->ehDispensaPorValor()) {
                $limite = $this->limiteDispensa->verificar($processo->orgao, $processo->ano, $amparo, (float) $processo->valor_estimado, $processo->natureza_despesa, $processo->id);

                if (! $limite['permitido']) {
                    $motivos[] = sprintf(
                        'O valor ultrapassa o limite da dispensa do %s em %d: R$ %s já contratados mais R$ %s deste processo passam do limite de R$ %s (art. 75, § 1º, fracionamento).',
                        $limite['dispositivo'], $processo->ano,
                        self::reais($limite['ja_contratado']), self::reais((float) $processo->valor_estimado), self::reais($limite['limite']),
                    );
                }
            }
        }

        if ($destino === S::AguardandoAutorizacao && ! $processo->documentoConcluido('parecer_juridico')) {
            $motivos[] = 'Conclua o parecer jurídico antes de enviar à autoridade (art. 53).';
        }

        if ($destino === S::Publicado && ! $processo->documentoConcluido('autorizacao')) {
            $motivos[] = 'Conclua a autorização da autoridade competente (art. 72, VIII).';
        }

        if ($destino === S::Homologado) {
            $itens = $processo->itens()->get();

            if ($itens->isEmpty()) {
                $motivos[] = 'O processo não tem itens.';
            }

            if (! Modalidade::from($processo->modalidade)->ehContratacaoDireta() && $itens->where('situacao', 'pendente')->whereNull('fornecedor_vencedor_id')->isNotEmpty()) {
                $motivos[] = 'Informe o vencedor de cada item, ou marque o item como cancelado ou deserto, antes de homologar.';
            }
        }

        if ($destino === S::Homologado) {
            // Quem recebeu propostas pelo NexLicita só homologa o que foi adjudicado (art. 71, IV e V).
            $semAdjudicar = $processo->itens()->where('situacao', 'pendente')->whereNull('adjudicado_em')->whereHas('propostas')->pluck('numero');

            if ($semAdjudicar->isNotEmpty()) {
                $motivos[] = 'Adjudique antes de homologar (art. 71, IV): item(ns) '.$semAdjudicar->implode(', ').'.';
            }
        }

        if ($motivos !== []) {
            throw new RegraViolada($motivos);
        }
    }

    private function publicarNoPncp(Processo $processo, S $destino, User $usuario): void
    {
        if ($destino === S::Publicado) {
            $this->fila->agendar($processo, 'compra', $processo->numero_controle_pncp ? 'retificar' : 'inserir', $usuario);
        } elseif ($processo->numero_controle_pncp && in_array($destino, [S::Revogado, S::Anulado, S::Homologado, S::Deserto, S::Fracassado], true)) {
            $this->fila->agendar($processo, 'compra', 'retificar', $usuario);
        }
    }

    public static function reais(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
