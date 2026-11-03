<?php

namespace App\Support;

use App\Models\Orgao;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\FluxosPadrao;
use Database\Seeders\ModelosPadrao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acesso de treinamento: uma turma é um órgão fictício, isolado como qualquer outro, com um usuário
 * por perfil e dados de exemplo. Nada da turma é publicado no PNCP nem sai por e-mail, e o acesso
 * acaba sozinho no fim do prazo. Encerrar não apaga nada: a trilha de auditoria continua íntegra.
 */
final class TurmaTreinamento
{
    /** Perfis da turma, na ordem em que aparecem na lista de acessos. */
    public const PERFIS = [
        'administrador-orgao' => 'Administração do órgão', 'autoridade' => 'Gabinete', 'planejamento' => 'Planejamento',
        'agente-contratacao' => 'Licitações', 'juridico' => 'Procuradoria', 'gestor-contrato' => 'Gestão de contratos',
        'requisitante' => 'Secretaria de Educação', 'auditor' => 'Controle interno',
    ];

    public function __construct(private GeradorDeCarga $gerador) {}

    /**
     * @return array{orgao: Orgao, acessos: list<array{perfil: string, nome: string, email: string, senha: string}>}
     */
    public function preparar(string $turma, ?int $dias = null): array
    {
        $turma = Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $turma));
        if ($turma === '' || strlen($turma) > 8) {
            throw new \InvalidArgumentException('Informe a turma com até 8 letras ou números, por exemplo 01 ou 2026A.');
        }
        $sigla = 'TREINO'.$turma;
        if (Orgao::where('sigla', $sigla)->exists()) {
            throw new \InvalidArgumentException("A turma {$turma} já existe. Encerre-a ou use outro nome.");
        }
        $dias = max(1, min(180, $dias ?? (int) config('nexplangov.treinamento.dias')));
        $dominio = (string) config('nexplangov.treinamento.dominio_email');

        return DB::transaction(function () use ($turma, $sigla, $dias, $dominio) {
            $orgao = Orgao::create([
                'nome' => "Órgão de treinamento (fictício) · turma {$turma}", 'sigla' => $sigla,
                'cnpj' => GeradorDeCarga::cnpj(crc32($sigla) % 100, crc32($sigla) % 1000, '998'),
                'esfera' => 'municipal', 'poder' => 'executivo', 'natureza_juridica' => 1031, 'uf' => 'RR', 'municipio' => 'Vale Verde',
                'pncp_ambiente' => 'homologacao', 'ativo' => true, 'treinamento' => true, 'treinamento_expira_em' => today()->addDays($dias),
            ]);
            ModelosPadrao::instalarPara($orgao);
            FluxosPadrao::instalarPara($orgao);

            foreach (['SEMAD' => 'Secretaria de Administração (fictícia)', 'SEMED' => 'Secretaria de Educação (fictícia)', 'SEMUS' => 'Secretaria de Saúde (fictícia)'] as $s => $n) {
                Unidade::withoutGlobalScopes()->create(['orgao_id' => $orgao->id, 'sigla' => $s, 'nome' => $n, 'tipo' => 'secretaria_adjunta']);
            }

            $acessos = [];
            $i = 0;
            foreach (self::PERFIS as $perfil => $setor) {
                $i++;
                $email = Str::before($perfil, '-').'.'.Str::lower($turma).'@'.$dominio;
                $senha = self::senha();
                $u = User::create(['orgao_id' => $orgao->id, 'name' => "Participante {$i} · {$setor}", 'email' => $email, 'password' => $senha, 'ativo' => true]);
                $u->forceFill(['email_verified_at' => now()])->save();
                $u->syncRoles([$perfil]);
                $acessos[] = ['perfil' => User::PERFIS[$perfil] ?? $perfil, 'nome' => $u->name, 'email' => $email, 'senha' => $senha];
            }

            // Um segundo gestor, para praticar a segregação entre gestor e fiscal (art. 7º, § 1º).
            $email = 'fiscal.'.Str::lower($turma).'@'.$dominio;
            $senha = self::senha();
            $fiscal = User::create(['orgao_id' => $orgao->id, 'name' => 'Participante 9 · Fiscalização de contratos', 'email' => $email, 'password' => $senha, 'ativo' => true]);
            $fiscal->forceFill(['email_verified_at' => now()])->save();
            $fiscal->syncRoles(['gestor-contrato']);
            $acessos[] = ['perfil' => (User::PERFIS['gestor-contrato'] ?? 'Gestor de contrato').' (fiscal)', 'nome' => $fiscal->name, 'email' => $email, 'senha' => $senha];

            $this->gerador->gerar($orgao, 12, User::where('orgao_id', $orgao->id)->role('planejamento')->first());

            Auditoria::registrar('treinamento', $orgao, "Turma de treinamento {$turma} preparada, com acesso até ".$orgao->treinamento_expira_em->format('d/m/Y'));

            return ['orgao' => $orgao, 'acessos' => $acessos];
        });
    }

    /** Desativa a turma e os acessos. Nada é apagado. */
    public function encerrar(Orgao $orgao): int
    {
        if (! $orgao->treinamento) {
            throw new \InvalidArgumentException("{$orgao->sigla} não é uma turma de treinamento.");
        }

        return DB::transaction(function () use ($orgao) {
            $n = User::where('orgao_id', $orgao->id)->where('ativo', true)->update(['ativo' => false]);
            $orgao->update(['ativo' => false, 'treinamento_expira_em' => min(today(), $orgao->treinamento_expira_em ?? today())]);
            Auditoria::registrar('treinamento', $orgao, "Turma de treinamento {$orgao->sigla} encerrada; {$n} acesso(s) desativado(s)");

            return $n;
        });
    }

    /** Encerra as turmas cujo prazo terminou. */
    public function encerrarVencidas(): int
    {
        $n = 0;
        foreach (Orgao::where('treinamento', true)->where('ativo', true)->whereDate('treinamento_expira_em', '<', today())->get() as $o) {
            $this->encerrar($o);
            $n++;
        }

        return $n;
    }

    private static function senha(): string
    {
        do {
            $s = Str::password(14, symbols: false);
        } while (! preg_match('/[a-z]/', $s) || ! preg_match('/[A-Z]/', $s) || ! preg_match('/\d/', $s));

        return $s;
    }
}
