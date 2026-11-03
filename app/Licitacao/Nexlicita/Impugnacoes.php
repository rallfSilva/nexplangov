<?php

namespace App\Licitacao\Nexlicita;

use App\Licitacao\Regras\PrazosEdital;
use App\Licitacao\RegraViolada;
use App\Models\Impugnacao;
use App\Models\Processo;
use App\Models\User;
use Illuminate\Support\Str;

/** Recebimento e resposta de impugnações e pedidos de esclarecimento (art. 164). */
final class Impugnacoes
{
    public function __construct(private PrazosEdital $prazos) {}

    public function registrar(Processo $p, string $tipo, string $nome, ?string $email, string $texto, string $canal = 'interno'): Impugnacao
    {
        if (! in_array($p->situacao, ['publicado', 'em_julgamento'], true)) {
            throw new RegraViolada(['O edital precisa estar publicado para receber impugnações e pedidos de esclarecimento.']);
        }
        if (! array_key_exists($tipo, Impugnacao::TIPOS)) {
            throw new RegraViolada(['Tipo inválido.']);
        }

        $agora = now();
        $abertura = $p->abertura_propostas_em;

        return Impugnacao::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'protocolo' => 'IMP-'.$agora->year.'-'.strtoupper(Str::random(6)),
            'tipo' => $tipo, 'canal' => $canal, 'autor_nome' => $nome, 'autor_email' => $email, 'texto' => $texto, 'recebida_em' => $agora,
            'limite_protocolo' => $abertura ? $this->prazos->limiteProtocolo($abertura)->toDateString() : null,
            'tempestiva' => $abertura ? $this->prazos->tempestiva($agora, $abertura) : true,
            'prazo_resposta_em' => $abertura ? $this->prazos->prazoResposta($agora, $abertura)->toDateString() : $agora->addWeekdays(3)->toDateString(),
        ]);
    }

    public function responder(Impugnacao $i, User $u, string $resultado, string $resposta): void
    {
        if ($i->situacao !== 'recebida') {
            throw new RegraViolada(['Este pedido já foi respondido.']);
        }
        if (! array_key_exists($resultado, Impugnacao::RESULTADOS) && $resultado !== 'nao_conhecida') {
            throw new RegraViolada(['Resultado inválido.']);
        }
        if (mb_strlen(trim($resposta)) < 10) {
            throw new RegraViolada(['Escreva a resposta (mínimo 10 caracteres). Ela é divulgada no sítio oficial.']);
        }

        $naoConhecida = $resultado === 'nao_conhecida';
        $i->update([
            'situacao' => $naoConhecida ? 'nao_conhecida' : 'respondida', 'resultado' => $naoConhecida ? null : $resultado,
            'resposta' => trim($resposta), 'respondida_por' => $u->id, 'respondida_em' => now(),
        ]);
    }
}
