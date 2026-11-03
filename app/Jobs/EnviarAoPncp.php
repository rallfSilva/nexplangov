<?php

namespace App\Jobs;

use App\Models\EnvioPncp;
use App\Pncp\NumeroControle;
use App\Pncp\PncpCliente;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Envia um registro ao PNCP. Estados: pendente → enviando → publicado ou erro.
 * Erros 400 e 422 são do conteúdo: vão para o usuário corrigir, sem nova tentativa.
 * Erros 5xx e de rede voltam para a fila com espera crescente.
 */
class EnviarAoPncp implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $envioId) {}

    public function handle(PncpCliente $cliente): void
    {
        $envio = EnvioPncp::withoutGlobalScopes()->find($this->envioId);

        // Envio de turma de treinamento nunca sai do sistema.
        if (! $envio || $envio->estado === 'publicado' || $envio->ambiente === 'treinamento') {
            return;
        }

        $envio->forceFill(['estado' => 'enviando', 'tentativas' => $envio->tentativas + 1])->save();

        try {
            $resposta = $cliente->enviar($envio->ambiente, $envio->metodo, $envio->rota, $envio->operacao === 'excluir' ? [] : $envio->payload);
        } catch (ConnectionException $e) {
            $envio->forceFill(['estado' => 'pendente', 'erro' => 'Sem conexão com o PNCP: '.$e->getMessage()])->save();
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 60);

            return;
        } catch (Throwable $e) {
            $envio->forceFill(['estado' => 'erro', 'erro' => $e->getMessage()])->save();

            return;
        }

        $dados = [
            'http_status' => $resposta->status(),
            'resposta' => $resposta->json() ?? ['corpo' => mb_substr($resposta->body(), 0, 2000)],
            'location' => $resposta->header('location') ?: null,
        ];

        if ($resposta->successful()) {
            $envio->forceFill($dados + ['estado' => 'publicado', 'erro' => null, 'publicado_em' => now()])->save();

            $alvo = $envio->publicavel()->withoutGlobalScopes()->first();
            $numero = NumeroControle::deLocation($dados['location']);

            if ($alvo && $envio->operacao === 'inserir' && $numero) {
                $alvo->forceFill(['numero_controle_pncp' => $numero])->save();
            }

            return;
        }

        if ($resposta->serverError() && $this->attempts() < $this->tries) {
            $envio->forceFill($dados + ['estado' => 'pendente', 'erro' => 'PNCP indisponível (HTTP '.$resposta->status().'). Nova tentativa automática.'])->save();
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $mensagem = $resposta->json('message') ?? $resposta->json('erros.0.mensagem') ?? mb_substr($resposta->body(), 0, 500);
        $envio->forceFill($dados + ['estado' => 'erro', 'erro' => "PNCP recusou (HTTP {$resposta->status()}): {$mensagem}"])->save();
    }
}
