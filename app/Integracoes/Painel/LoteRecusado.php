<?php

namespace App\Integracoes\Painel;

use RuntimeException;

/** O Painel de Compras recusou o lote pelo conteúdo (4xx): corrigir o dado, não reenviar igual. */
class LoteRecusado extends RuntimeException {}
