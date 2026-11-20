<?php

namespace App\Integracoes\NotasFiscais;

use App\Integracoes\IntegracaoIndisponivel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Preços efetivamente pagos, a partir de notas fiscais eletrônicas (art. 23, § 1º, V, da Lei 14.133/2021).
 *
 * Não há API pública e aberta da base nacional de NF-e para pesquisa de preços: o acesso se dá por convênio com a
 * Receita Federal ou com a Secretaria da Fazenda do estado (ou por serviço contratado que já tenha esse acesso).
 * Por isso a fonte fica desligada até o órgão informar `NFE_PRECOS_URL` e `NFE_PRECOS_TOKEN`.
 *
 * Contrato esperado do serviço conveniado (o adaptador do convênio converte para este formato):
 * GET {url}?descricao=...&catmat=...&desde=AAAA-MM-DD, cabeçalho "Authorization: Bearer {token}", resposta
 * {"itens": [{"chaveAcesso", "dataEmissao", "valorUnitario", "quantidade", "unidade", "descricao", "cnpjEmitente",
 * "nomeEmitente", "uf", "url"}]}. Ver docs/integracoes.md.
 */
final class PrecosNotasFiscais
{
    public static function habilitada(): bool
    {
        return filled(config('nexplangov.notas_fiscais.url')) && filled(config('nexplangov.notas_fiscais.token'));
    }

    /** @return list<array<string, mixed>> */
    public function buscar(string $descricao, ?string $catmat): array
    {
        if (! self::habilitada() || mb_strlen(trim($descricao)) < 3) {
            return [];
        }

        return Cache::remember('nfe:precos:v1:'.md5(mb_strtolower($descricao.'|'.$catmat)), now()->addHours(6), fn () => $this->consultar($descricao, $catmat));
    }

    private function consultar(string $descricao, ?string $catmat): array
    {
        $cfg = config('nexplangov.notas_fiscais');
        try {
            $r = Http::timeout($cfg['timeout'])->acceptJson()->withToken($cfg['token'])->retry(2, 500, throw: false)
                ->get($cfg['url'], array_filter(['descricao' => mb_substr($descricao, 0, 200), 'catmat' => $catmat, 'desde' => today()->subYear()->toDateString()]));
        } catch (Throwable $e) {
            report($e);
            throw new IntegracaoIndisponivel('Não foi possível consultar a base de notas fiscais agora.');
        }
        if (! $r->successful()) {
            throw new IntegracaoIndisponivel("A base de notas fiscais respondeu com erro {$r->status()}.");
        }

        $saida = [];
        foreach (array_slice($r->json('itens') ?? [], 0, 60) as $n) {
            if (is_array($n) && ($x = $this->normalizar($n, $cfg['portal_consulta']))) {
                $saida[] = $x;
            }
        }
        usort($saida, fn ($a, $b) => strcmp($b['data'], $a['data']));

        return $saida;
    }

    private function normalizar(array $n, string $portal): ?array
    {
        $chave = preg_replace('/\D/', '', (string) ($n['chaveAcesso'] ?? ''));
        $valor = $n['valorUnitario'] ?? null;
        if (strlen($chave) !== 44 || ! is_numeric($valor) || (float) $valor <= 0 || empty($n['dataEmissao'])) {
            return null;
        }
        try {
            $data = Carbon::parse($n['dataEmissao'])->toDateString();
        } catch (Throwable) {
            return null;
        }
        if ($data < today()->subYear()->toDateString()) {
            return null;
        }
        $cnpj = preg_replace('/\D/', '', (string) ($n['cnpjEmitente'] ?? ''));

        return [
            'id' => "nfe:{$chave}",
            'valor' => round((float) $valor, 4),
            'data' => $data,
            'orgao' => trim(($n['nomeEmitente'] ?? 'Emitente não informado').(isset($n['uf']) ? " ({$n['uf']})" : '')),
            'uasg' => null,
            'fornecedor' => $n['nomeEmitente'] ?? null,
            'cnpj' => strlen($cnpj) === 14 ? vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($cnpj)) : null,
            'unidade' => $n['unidade'] ?? null,
            'quantidade' => is_numeric($n['quantidade'] ?? null) ? (float) $n['quantidade'] : null,
            'descricao' => isset($n['descricao']) ? mb_substr((string) $n['descricao'], 0, 1000) : null,
            // A chave de acesso permite conferir a nota no portal da NF-e ou da SEFAZ de origem.
            'url' => is_string($n['url'] ?? null) && str_starts_with($n['url'], 'https://') ? $n['url'] : $portal,
            'identificacao' => 'NF-e chave '.implode(' ', str_split($chave, 4)),
            'origem' => 'Nota fiscal eletrônica',
            'fonte' => 'notas_fiscais',
        ];
    }
}
