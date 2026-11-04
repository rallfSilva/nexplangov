<?php

namespace App\Jobs;

use App\Models\Ata;
use App\Models\Contrato;
use App\Models\EnvioPncp;
use App\Models\User;
use App\Pncp\FilaPncp;
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

    public function handle(PncpCliente $cliente, FilaPncp $fila): void
    {
        $envio = EnvioPncp::withoutGlobalScopes()->find($this->envioId);

        // Registro antigo das turmas de treinamento (recurso removido): nunca sai do sistema.
        if (! $envio || $envio->estado === 'publicado' || $envio->ambiente === 'treinamento') {
            return;
        }

        $envio->forceFill(['estado' => 'enviando', 'tentativas' => $envio->tentativas + 1])->save();

        try {
            $documento = $envio->arquivo_caminho
                ? ['caminho' => $envio->arquivo_caminho, 'titulo' => $envio->documento_titulo, 'tipo' => (int) $envio->tipo_documento_id]
                : null;
            // Na inclusão da compra o JSON vai na parte "compra" do multipart; nos endpoints de arquivo, só o arquivo.
            $parteJson = $documento && $envio->recurso === 'compra' ? 'compra' : null;
            $resposta = $cliente->enviar($envio->ambiente, $envio->metodo, $envio->rota, $envio->payload ?? [], $documento, $parteJson);
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
            if ($alvo && $envio->operacao === 'inserir') {
                $this->guardarNumero($alvo, $envio->recurso, $dados['location']);
                $this->enviarArquivos($fila, $alvo->refresh(), $envio);
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

    /** Guarda a referência devolvida pelo PNCP no registro, conforme o tipo. */
    private function guardarNumero($alvo, string $recurso, ?string $location): void
    {
        match ($recurso) {
            'compra', 'contrato', 'termo', 'pca' => ($n = NumeroControle::deLocation($location))
                ? $alvo->forceFill(['numero_controle_pncp' => $n])->save() : null,
            'ata' => ($n = NumeroControle::ultimoSequencial($location))
                ? $alvo->forceFill(['numero_controle_pncp' => (string) $n])->save() : null,
            'resultado' => ($n = NumeroControle::ultimoSequencial($location))
                ? $alvo->forceFill(['sequencial_resultado_pncp' => $n])->save() : null,
            default => null,
        };
    }

    /** Depois do contrato e da ata publicados, segue o arquivo (contrato assinado; ata em PDF). */
    private function enviarArquivos(FilaPncp $fila, $alvo, EnvioPncp $envio): void
    {
        $usuario = $envio->user_id ? User::find($envio->user_id) : null;

        if ($alvo instanceof Contrato && $envio->recurso === 'contrato' && $alvo->arquivo_caminho && $alvo->numero_controle_pncp) {
            $fila->agendar($alvo, 'arquivo_contrato', 'inserir', $usuario);
        }

        if ($alvo instanceof Ata && $envio->recurso === 'ata' && $alvo->numero_controle_pncp) {
            $fila->agendar($alvo, 'arquivo_ata', 'inserir', $usuario);
        }
    }
}
