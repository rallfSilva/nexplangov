<?php

namespace App\Pncp;

use App\Jobs\EnviarAoPncp;
use App\Models\EnvioPncp;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Coloca um envio na fila. O corpo é montado na hora do agendamento (retrato do que foi decidido)
 * e o envio de fato acontece no job, com novas tentativas para falhas do PNCP.
 */
class FilaPncp
{
    public function __construct(private MontadorPayload $montador) {}

    public function agendar(Model $alvo, string $recurso, string $operacao, ?User $usuario = null): EnvioPncp
    {
        // Nunca reenviar uma inclusão que já tem número no PNCP: vira retificação com o objeto completo.
        if ($operacao === 'inserir' && $alvo->getAttribute('numero_controle_pncp')) {
            $operacao = 'retificar';
        }

        $montado = $this->montador->montar($alvo, $recurso, $operacao);
        $orgao = $alvo->getRelationValue('orgao') ?? $alvo->orgao;

        $envio = EnvioPncp::create([
            'orgao_id' => $alvo->getAttribute('orgao_id'),
            'user_id' => $usuario?->id,
            'publicavel_type' => $alvo->getMorphClass(),
            'publicavel_id' => $alvo->getKey(),
            'recurso' => $recurso,
            'operacao' => $operacao,
            'estado' => $montado['pendencias'] === [] ? 'pendente' : 'erro',
            'ambiente' => $orgao?->pncp_ambiente ?? config('nexplangov.pncp.ambiente'),
            'payload' => $montado['corpo'],
            'metodo' => $montado['metodo'],
            'rota' => $montado['rota'],
            'erro' => $montado['pendencias'] === [] ? null : 'Antes de enviar: '.implode(' ', $montado['pendencias']),
        ]);

        if ($envio->estado === 'pendente') {
            EnviarAoPncp::dispatch($envio->id)->afterCommit();
        }

        return $envio;
    }
}
