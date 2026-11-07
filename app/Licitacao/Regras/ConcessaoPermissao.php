<?php

namespace App\Licitacao\Regras;

/**
 * Permissão extra para um servidor, concedida pelo administrador do órgão (11.4), sem quebrar a segregação de
 * funções (art. 7º, § 1º: vedada a designação do mesmo agente para funções suscetíveis a riscos). Atribuições
 * pessoais por lei (parecer jurídico, art. 53; decisão da autoridade, art. 71) e as de administração do sistema
 * só vêm do perfil, nunca de concessão avulsa.
 */
final class ConcessaoPermissao
{
    /** Só pelo perfil. */
    public const NAO_CONCEDIVEIS = ['orgaos.gerenciar', 'master.ver', 'usuarios.gerenciar', 'parametros.gerenciar', 'devolucao.gerar', 'processos.parecer', 'processos.autorizar', 'pca.aprovar'];

    /** Funções que a mesma pessoa não acumula (art. 7º, § 1º). */
    public const INCOMPATIVEIS = [
        ['processos.parecer', 'processos.gerenciar', 'Quem instrui o processo não emite o parecer jurídico sobre ele (art. 7º, § 1º, e art. 53).'],
        ['processos.parecer', 'licitacao.conduzir', 'Quem conduz a licitação não emite o parecer jurídico (art. 7º, § 1º, e art. 53).'],
        ['processos.autorizar', 'processos.gerenciar', 'Quem instrui o processo não o autoriza nem homologa (art. 7º, § 1º, e art. 71).'],
        ['processos.autorizar', 'licitacao.conduzir', 'Quem conduz a licitação não a homologa (art. 7º, § 1º, e art. 71).'],
        ['pca.aprovar', 'pca.gerenciar', 'Quem monta o PCA não o aprova (art. 7º, § 1º, e art. 12, VII).'],
    ];

    public const JUSTIFICATIVA_MINIMA = 20;

    /**
     * @param  list<string>  $atuais  permissões que o servidor já tem (perfil e concessões)
     * @return list<string>
     */
    public static function motivos(string $perfil, string $permissao, array $atuais, ?string $justificativa): array
    {
        if (in_array($permissao, self::NAO_CONCEDIVEIS, true)) {
            return ['Esta permissão só vem do perfil do servidor: é atribuição pessoal ou de administração do sistema.'];
        }
        if (in_array($permissao, $atuais, true)) {
            return ['O servidor já tem esta permissão.'];
        }
        if ($perfil === 'auditor' && ! str_ends_with($permissao, '.ver')) {
            return ['O auditor só recebe permissões de consulta: quem fiscaliza não executa (art. 7º, § 1º, e art. 169).'];
        }
        $m = [];
        foreach (self::INCOMPATIVEIS as [$a, $b, $porque]) {
            if (($permissao === $a && in_array($b, $atuais, true)) || ($permissao === $b && in_array($a, $atuais, true))) {
                $m[] = $porque;
            }
        }
        if (mb_strlen(trim((string) $justificativa)) < self::JUSTIFICATIVA_MINIMA) {
            $m[] = 'Justifique a concessão (mínimo '.self::JUSTIFICATIVA_MINIMA.' caracteres): ela fica na trilha de auditoria.';
        }

        return $m;
    }
}
