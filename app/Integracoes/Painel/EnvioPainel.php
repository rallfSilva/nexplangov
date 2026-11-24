<?php

namespace App\Integracoes\Painel;

use App\Integracoes\IntegracaoIndisponivel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Envia ao painel os lotes de um modelo (ou da carga inicial), tipo a tipo, na ordem de dependência, e registra cada envio. */
final class EnvioPainel
{
    public function __construct(private ClientePainel $cliente) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $lotes  tipo → registros, já na ordem de envio
     * @return list<array{tipo: string, estado: string, lote: ?int, erro: ?string}>
     *
     * @throws IntegracaoIndisponivel o envio para no primeiro tipo que falha por rede (os seguintes dependem dele)
     */
    public function enviar(array $lotes, ?Model $origem = null, ?int $orgaoId = null, ?string $referencia = null): array
    {
        $resultado = [];
        foreach ($lotes as $tipo => $registros) {
            foreach (array_chunk($registros, ClientePainel::LIMITE_POR_LOTE) as $parte) {
                $chave = 'nexplangov-'.Str::lower($tipo).'-'.Str::uuid();
                $base = ['orgao_id' => $orgaoId ?? $origem?->getAttribute('orgao_id'), 'origem_type' => $origem?->getMorphClass(), 'origem_id' => $origem?->getKey(),
                    'tipo' => $tipo, 'registros' => count($parte), 'chave' => $chave, 'created_at' => now(), 'updated_at' => now()];
                try {
                    $lote = $this->cliente->enviar($tipo, $parte, $chave, $referencia);
                    DB::table('envios_painel')->insert($base + ['estado' => 'enviado', 'lote_painel_id' => $lote['id'] ?? null]);
                    $resultado[] = ['tipo' => $tipo, 'estado' => 'enviado', 'lote' => $lote['id'] ?? null, 'erro' => null];
                } catch (LoteRecusado $e) {
                    // Conteúdo recusado: registra e segue; os tipos seguintes podem não depender deste registro.
                    DB::table('envios_painel')->insert($base + ['estado' => 'erro', 'erro' => mb_substr($e->getMessage(), 0, 2000)]);
                    $resultado[] = ['tipo' => $tipo, 'estado' => 'erro', 'lote' => null, 'erro' => $e->getMessage()];
                } catch (IntegracaoIndisponivel $e) {
                    DB::table('envios_painel')->insert($base + ['estado' => 'indisponivel', 'erro' => mb_substr($e->getMessage(), 0, 2000)]);
                    throw $e;
                }
            }
        }

        return $resultado;
    }
}
