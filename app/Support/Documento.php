<?php

namespace App\Support;

/** CPF e CNPJ: validação dos dígitos, formatação, máscara e HMAC para busca sem expor o dado. */
final class Documento
{
    public static function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    public static function cnpjValido(string $valor): bool
    {
        $c = self::digitos($valor);

        if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) {
            return false;
        }

        foreach ([12, 13] as $t) {
            $pesos = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $soma = 0;

            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $c[$i] * $pesos[$i];
            }

            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;

            if ((int) $c[$t] !== $dv) {
                return false;
            }
        }

        return true;
    }

    public static function cpfValido(string $valor): bool
    {
        $c = self::digitos($valor);

        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;

            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $c[$i] * (($t + 1) - $i);
            }

            $dv = ((10 * $soma) % 11) % 10;

            if ((int) $c[$t] !== $dv) {
                return false;
            }
        }

        return true;
    }

    public static function formatarCnpj(string $valor): string
    {
        $c = self::digitos($valor);

        return strlen($c) === 14 ? vsprintf('%s.%s.%s/%s-%s', [substr($c, 0, 2), substr($c, 2, 3), substr($c, 5, 3), substr($c, 8, 4), substr($c, 12, 2)]) : $valor;
    }

    /** ***.456.789-** (padrão de mascaramento usado em publicações oficiais). */
    public static function mascararCpf(string $valor): string
    {
        $c = self::digitos($valor);

        return strlen($c) === 11 ? '***.'.substr($c, 3, 3).'.'.substr($c, 6, 3).'-**' : '***';
    }

    public static function hash(string $valor): string
    {
        return hash_hmac('sha256', self::digitos($valor), (string) config('app.key'));
    }
}
