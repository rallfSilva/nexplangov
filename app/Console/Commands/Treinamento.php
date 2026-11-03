<?php

namespace App\Console\Commands;

use App\Models\Orgao;
use App\Support\TurmaTreinamento;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexplangov:treinamento {acao : preparar, encerrar, encerrar-vencidas ou listar} {--turma= : Nome da turma (até 8 letras ou números)} {--dias= : Dias de acesso (padrão do .env)}')]
#[Description('Turmas de treinamento com dados fictícios: nada vai ao PNCP nem sai por e-mail')]
class Treinamento extends Command
{
    public function handle(TurmaTreinamento $turmas): int
    {
        try {
            return match ($this->argument('acao')) {
                'preparar' => $this->preparar($turmas),
                'encerrar' => $this->encerrar($turmas),
                'encerrar-vencidas' => $this->vencidas($turmas),
                'listar' => $this->listar(),
                default => $this->falha('Ação desconhecida. Use preparar, encerrar, encerrar-vencidas ou listar.'),
            };
        } catch (\InvalidArgumentException $e) {
            return $this->falha($e->getMessage());
        }
    }

    private function preparar(TurmaTreinamento $turmas): int
    {
        $r = $turmas->preparar((string) $this->option('turma'), $this->option('dias') ? (int) $this->option('dias') : null);
        $this->info("Turma pronta: {$r['orgao']->nome}, acesso até {$r['orgao']->treinamento_expira_em->format('d/m/Y')}.");
        $this->table(['Perfil', 'Nome', 'E-mail', 'Senha'], array_map(fn ($a) => [$a['perfil'], $a['nome'], $a['email'], $a['senha']], $r['acessos']));
        $this->warn('As senhas aparecem só agora. Entregue a cada participante e peça que troque no primeiro acesso.');

        return self::SUCCESS;
    }

    private function encerrar(TurmaTreinamento $turmas): int
    {
        $orgao = Orgao::where('sigla', 'TREINO'.strtoupper((string) $this->option('turma')))->first();
        if (! $orgao) {
            return $this->falha('Turma não encontrada.');
        }
        $n = $turmas->encerrar($orgao);
        $this->info("Turma {$orgao->sigla} encerrada: {$n} acesso(s) desativado(s). Os registros continuam na auditoria.");

        return self::SUCCESS;
    }

    private function vencidas(TurmaTreinamento $turmas): int
    {
        $this->info($turmas->encerrarVencidas().' turma(s) vencida(s) encerrada(s).');

        return self::SUCCESS;
    }

    private function listar(): int
    {
        $this->table(['Turma', 'Situação', 'Acesso até', 'Usuários'], Orgao::where('treinamento', true)->withCount('usuarios')->orderBy('sigla')->get()
            ->map(fn (Orgao $o) => [$o->sigla, $o->ativo ? 'ativa' : 'encerrada', $o->treinamento_expira_em?->format('d/m/Y'), $o->usuarios_count])->all());

        return self::SUCCESS;
    }

    private function falha(string $m): int
    {
        $this->error($m);

        return self::FAILURE;
    }
}
