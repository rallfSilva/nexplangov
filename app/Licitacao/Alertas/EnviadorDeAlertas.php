<?php

namespace App\Licitacao\Alertas;

use App\Models\AlertaEnviado;
use App\Models\User;
use App\Notifications\AlertaDePrazos;
use Carbon\CarbonInterface;

/** Envia um resumo por pessoa e grava no livro o que já foi avisado, para não repetir o mesmo aviso. */
final class EnviadorDeAlertas
{
    public function __construct(private Detector $detector) {}

    /** @return int Quantidade de e-mails enviados. */
    public function enviar(?CarbonInterface $hoje = null): int
    {
        $porUsuario = [];
        foreach ($this->detector->detectar($hoje) as $a) {
            foreach ($a['users'] as $uid) {
                $porUsuario[$uid][] = $a;
            }
        }

        $enviados = 0;
        foreach (User::whereIn('id', array_keys($porUsuario))->where('ativo', true)
            ->where(fn ($q) => $q->whereNull('orgao_id')->orWhereHas('orgao', fn ($o) => $o->where('treinamento', false)))->get() as $u) {
            $ja = AlertaEnviado::where('user_id', $u->id)->pluck('chave')->all();
            $novos = array_values(array_filter($porUsuario[$u->id], fn ($a) => ! in_array($a['chave'], $ja, true)));
            if (! $novos) {
                continue;
            }

            $u->notify(new AlertaDePrazos($novos));
            foreach ($novos as $a) {
                AlertaEnviado::firstOrCreate(['user_id' => $u->id, 'chave' => $a['chave']], ['orgao_id' => $a['orgao_id'], 'enviado_em' => now()]);
            }
            $enviados++;
        }

        return $enviados;
    }
}
