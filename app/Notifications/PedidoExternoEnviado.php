<?php

namespace App\Notifications;

use App\Models\SolicitacaoExterna;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail a pessoa de fora do órgão com o link pessoal para dar ciência, assinar ou enviar documentos. */
class PedidoExternoEnviado extends Notification
{
    use Queueable;

    public function __construct(public SolicitacaoExterna $pedido, public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $s = $this->pedido;
        $orgao = $s->processo->orgao?->nome ?? 'Órgão público';
        $acao = match ($s->tipo) {
            'assinatura' => 'Ler e assinar',
            'documentos' => 'Enviar os documentos',
            default => 'Ler e registrar ciência',
        };

        return (new MailMessage)
            ->subject("{$orgao}: {$s->rotuloTipo()} no processo {$s->processo->identificacao()}")
            ->greeting('Olá, '.explode(' ', $s->nome)[0].'.')
            ->line("{$orgao} enviou a você um pedido no processo {$s->processo->identificacao()}.")
            ->line($s->mensagem)
            ->action($acao, route('externo.mostrar', $this->token))
            ->line('O link é pessoal e vale até '.$s->prazo_em->format('d/m/Y').'. Não o encaminhe a outras pessoas.')
            ->salutation('Enviado pelo NexPlanGov · Desenvolvido por NexMind');
    }
}
