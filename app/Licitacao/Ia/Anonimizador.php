<?php

namespace App\Licitacao\Ia;

/**
 * Tira dado pessoal do texto antes de mandar para a IA (LGPD, art. 6º, III: necessidade).
 * CPF, e-mail, telefone e CEP viram marcadores. CNPJ de empresa não é dado pessoal e fica.
 */
final class Anonimizador
{
    public static function limpar(string $texto): string
    {
        $regras = [
            '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/' => '[CPF]',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[E-MAIL]',
            '/(\(?\b\d{2}\)?\s?)?\b9?\d{4}-?\d{4}\b/' => '[TELEFONE]',
            '/\b\d{5}-\d{3}\b/' => '[CEP]',
        ];

        // CNPJ primeiro fica protegido, para a regra de telefone não pegar pedaços dele.
        $cnpjs = [];
        $texto = preg_replace_callback('/\b\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}\b/', function ($m) use (&$cnpjs) {
            $cnpjs[] = $m[0];

            return '§CNPJ'.(count($cnpjs) - 1).'§';
        }, $texto);

        $texto = preg_replace(array_keys($regras), array_values($regras), $texto);

        return preg_replace_callback('/§CNPJ(\d+)§/', fn ($m) => $cnpjs[(int) $m[1]], $texto);
    }
}
