<?php

namespace App\Models\Concerns;

use App\Support\Auditoria;

/**
 * Registra na trilha de auditoria toda criação, alteração e exclusão do modelo,
 * guardando os valores antes e depois. Campos sensíveis nunca são gravados.
 */
trait Auditable
{
    /** @var list<string> */
    protected static array $camposNuncaAuditados = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'ultimo_acesso_em', 'updated_at', 'created_at',
    ];

    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            Auditoria::registrar('criado', $model, $model->rotuloAuditoria().' criad'.$model->generoAuditoria(), null, $model->valoresAuditaveis($model->getAttributes()));
        });

        static::updated(function ($model) {
            $novos = $model->valoresAuditaveis($model->getChanges());

            if ($novos === []) {
                return;
            }

            $antigos = array_intersect_key($model->valoresAuditaveis($model->getOriginal()), $novos);
            $alterados = array_keys(array_diff_key($model->getChanges(), array_flip($model->camposSensiveis())));

            Auditoria::registrar('alterado', $model, $model->rotuloAuditoria().' alterad'.$model->generoAuditoria().' ('.implode(', ', $alterados).')', $antigos, $novos);
        });

        static::deleted(function ($model) {
            Auditoria::registrar('excluido', $model, $model->rotuloAuditoria().' excluíd'.$model->generoAuditoria(), $model->valoresAuditaveis($model->getAttributes()), null);
        });
    }

    /**
     * @param  array<string, mixed>  $valores
     * @return array<string, mixed>
     */
    protected function valoresAuditaveis(array $valores): array
    {
        $valores = array_diff_key($valores, array_flip($this->camposSensiveis()));

        foreach ($valores as $campo => $valor) {
            if ($valor instanceof \DateTimeInterface) {
                $valores[$campo] = $valor->format('Y-m-d H:i:s');
            }
        }

        return $valores;
    }

    /** @return list<string> campos que nunca vão para a trilha (o modelo pode acrescentar os seus). */
    protected function camposSensiveis(): array
    {
        return [...static::$camposNuncaAuditados, ...$this->camposSensiveisDoModelo()];
    }

    /** @return list<string> */
    protected function camposSensiveisDoModelo(): array
    {
        return [];
    }

    /** Terminação de gênero usada na descrição: "o" (criado) ou "a" (criada). */
    protected function generoAuditoria(): string
    {
        return 'o';
    }

    protected function rotuloAuditoria(): string
    {
        return class_basename($this).' #'.$this->getKey();
    }
}
