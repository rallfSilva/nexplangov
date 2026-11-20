<?php

namespace App\Notifications;

use App\Models\LoteCotacaoIa;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avisa quem pediu que a cotação por IA dos itens terminou. */
class CotacaoIaConcluida extends Notification
{
    use Queueable;

    public function __construct(public LoteCotacaoIa $lote) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $l = $this->lote;
        $p = $l->processo()->withoutGlobalScopes()->first();

        return (new MailMessage)
            ->subject("NexPlanGov: cotação por IA concluída no processo {$p->identificacao()}")
            ->line("{$l->itens_cotados} de {$l->total_itens} item(ns) receberam preços de contratações similares (art. 23, § 1º, II).")
            ->line('Confira as cotações e a justificativa de cada item antes de seguir: a IA sugere, quem assina a pesquisa responde por ela.')
            ->action('Abrir os itens e preços', route('processos.show', [$p->id, 'aba' => 'itens']))
            ->salutation('Equipe NexPlanGov · Desenvolvido por NexMind');
    }
}
