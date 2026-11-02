<?php

namespace App\Support;

use App\Models\Orgao;
use App\Models\RegistroAuditoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class Auditoria
{
    /**
     * @param  array<string, mixed>|null  $antigos
     * @param  array<string, mixed>|null  $novos
     */
    public static function registrar(
        string $evento,
        ?Model $alvo,
        string $descricao,
        ?array $antigos = null,
        ?array $novos = null,
        ?User $usuario = null,
    ): RegistroAuditoria {
        $usuario ??= Auth::hasUser() ? Auth::user() : null;

        $orgaoId = $alvo?->getAttribute('orgao_id') ?? $usuario?->orgao_id;

        if ($alvo instanceof Orgao) {
            $orgaoId = $alvo->getKey();
        }

        $dados = [
            'orgao_id' => $orgaoId,
            'user_id' => $usuario?->getKey(),
            'evento' => $evento,
            'auditable_type' => $alvo?->getMorphClass(),
            'auditable_id' => $alvo?->getKey(),
            'descricao' => mb_substr($descricao, 0, 255),
            'valores_antigos' => $antigos,
            'valores_novos' => $novos,
            'ip' => app()->runningInConsole() ? null : Request::ip(),
            'user_agent' => app()->runningInConsole() ? 'console' : mb_substr((string) Request::userAgent(), 0, 500),
        ];

        // Encadeamento por hash: o trava de transação garante que dois registros simultâneos
        // não apontem para o mesmo anterior.
        return DB::transaction(function () use ($dados) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(7140133)');
            }

            $anterior = RegistroAuditoria::withoutGlobalScopes()->latest('id')->value('hash');
            $dados['created_at'] = now();
            $dados['hash_anterior'] = $anterior;
            $dados['hash'] = self::calcularHash($dados, $anterior);

            return RegistroAuditoria::create($dados);
        });
    }

    /** @param  array<string, mixed>  $dados */
    public static function calcularHash(array $dados, ?string $anterior): string
    {
        $campos = ['orgao_id', 'user_id', 'evento', 'auditable_type', 'auditable_id', 'descricao', 'valores_antigos', 'valores_novos', 'ip'];
        $base = [];

        foreach ($campos as $c) {
            $base[$c] = $dados[$c] ?? null;
        }

        $base['created_at'] = $dados['created_at'] instanceof \DateTimeInterface ? $dados['created_at']->format('Y-m-d H:i:s') : (string) $dados['created_at'];

        return hash('sha256', ($anterior ?? '').'|'.json_encode(self::ordenar($base), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** JSONB do PostgreSQL reordena as chaves; ordenamos para o hash ser reproduzível. */
    private static function ordenar(mixed $valor): mixed
    {
        if (is_array($valor)) {
            if (! array_is_list($valor)) {
                ksort($valor);
            }

            return array_map(self::ordenar(...), $valor);
        }

        return $valor;
    }

    /**
     * Confere a cadeia inteira. Devolve o id do primeiro registro adulterado, ou null se está íntegra.
     */
    public static function verificarCadeia(): ?int
    {
        $anterior = null;

        foreach (RegistroAuditoria::withoutGlobalScopes()->orderBy('id')->lazyById(500) as $r) {
            $dados = $r->getAttributes();
            $dados['valores_antigos'] = $r->valores_antigos;
            $dados['valores_novos'] = $r->valores_novos;
            $dados['created_at'] = $r->created_at;

            if ($r->hash_anterior !== $anterior || $r->hash !== self::calcularHash($dados, $anterior)) {
                return $r->id;
            }

            $anterior = $r->hash;
        }

        return null;
    }
}
