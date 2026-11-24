<?php

namespace App\Jobs;

use App\Integracoes\IntegracaoIndisponivel;
use App\Integracoes\Painel\ClientePainel;
use App\Integracoes\Painel\EnvioPainel;
use App\Integracoes\Painel\MapeadorPainel;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envia ao Painel de Compras o estado atual de um registro (e do que ele depende). Várias alterações
 * seguidas do mesmo registro viram um envio só (único até começar + espera curta;
 * o que mudar depois de começar gera um novo envio). O retrato é montado na
 * hora do envio, então sempre vai a versão mais recente. Falha de rede volta para a fila com espera crescente.
 */
class EnviarAoPainel implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 10800];

    public int $uniqueFor = 120;

    /** @param class-string<Model> $modelo */
    public function __construct(public string $modelo, public int $id)
    {
        $this->onQueue(config('nexplangov.painel.fila', 'painel'));
    }

    public function uniqueId(): string
    {
        return $this->modelo.':'.$this->id;
    }

    public function handle(ClientePainel $cliente, EnvioPainel $envio): void
    {
        $m = $this->modelo::query()->find($this->id);
        if (! $m || ! $cliente->habilitado()) {
            return;
        }
        $lotes = MapeadorPainel::para($m);
        if ($lotes === []) {
            return;
        }

        try {
            $envio->enviar($lotes, $m);
        } catch (IntegracaoIndisponivel) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
