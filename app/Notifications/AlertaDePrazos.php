<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Resumo diário de prazos por e-mail. Não leva dado pessoal, só o que pede atenção e o link para a tela. */
class AlertaDePrazos extends Notification
{
    use Queueable;

    /** @param list<array<string, mixed>> $alertas */
    public function __construct(public array $alertas) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $n = count($this->alertas);
        $m = (new MailMessage)->subject("NexPlanGov: {$n} prazo(s) pedem a sua atenção")->greeting('Olá, '.explode(' ', trim($notifiable->name))[0].'!')
            ->line($n === 1 ? 'Este item pede a sua atenção:' : 'Estes itens pedem a sua atenção:');

        foreach ($this->alertas as $a) {
            $m->line(($a['urgente'] ? '⚠ ' : '').$a['titulo'].'. '.$a['texto'].' '.url($a['url']));
        }

        return $m->action('Abrir o NexPlanGov', url('/painel'))->salutation('Equipe NexPlanGov · Desenvolvido por NexMind');
    }
}
