<?php

namespace App\Support;

use App\Licitacao\Regras\ParametrosOrgao;
use App\Models\ParametroOrgao;

/**
 * Lê o parâmetro do órgão: o valor ajustado na tela ou, sem ajuste, o padrão de config/nexplangov.php.
 * Sem órgão informado, usa o do usuário logado. Serve também às rotinas agendadas, que passam o órgão.
 */
final class Parametros
{
    public static function valor(string $chave, ?int $orgaoId = null): mixed
    {
        $def = ParametrosOrgao::DEFINICOES[$chave] ?? throw new \InvalidArgumentException("Parâmetro {$chave} não existe.");
        $padrao = config($def['config']);
        $orgaoId ??= auth()->user()?->orgao_id;
        if ($orgaoId === null) {
            return $padrao;
        }
        // Leitura de configuração por órgão explícito: o escopo global do órgão não vale para rotinas agendadas.
        $guardado = ParametroOrgao::withoutGlobalScopes()->where('orgao_id', $orgaoId)->where('chave', $chave)->value('valor');

        return ParametrosOrgao::ler($chave, $guardado, $padrao);
    }
}
