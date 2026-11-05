<?php

namespace App\Notifications;

use App\Models\SolicitacaoExterna;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avisa quem fez o pedido que a pessoa de fora respondeu. */
class PedidoExternoRespondido extends Notification
{
    use Queueable;

    public function __construct(public SolicitacaoExterna $pedido) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->pedido;

        return (new MailMessage)
            ->subject("NexPlanGov: {$s->nome} respondeu ao pedido no processo {$s->processo->identificacao()}")
            ->line("{$s->rotuloTipo()}: {$s->resposta}")
            ->action('Abrir o processo', route('processos.show', [$s->processo_id, 'aba' => 'autos']))
            ->salutation('Equipe NexPlanGov · Desenvolvido por NexMind');
    }
}
