<?php

namespace App\Licitacao;

use RuntimeException;

/** Uma regra da Lei 14.133 impediu a operação. Carrega a lista de motivos para mostrar ao usuário. */
class RegraViolada extends RuntimeException
{
    /** @param  list<string>  $motivos */
    public function __construct(public readonly array $motivos)
    {
        parent::__construct(implode(' ', $motivos));
    }
}
