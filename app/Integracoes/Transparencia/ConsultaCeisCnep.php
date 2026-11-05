<?php

namespace App\Integracoes\Transparencia;

use App\Integracoes\IntegracaoIndisponivel;
use App\Licitacao\Regras\ImpedimentoCadastral;
use App\Models\ConsultaImpedimento;
use App\Models\Fornecedor;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta preventiva de sanções no CEIS e no CNEP (art. 161 da Lei 14.133), pela API de dados do Portal da
 * Transparência (chave gratuita, enviada no cabeçalho "chave-api-dados").
 *
 * Os nomes dos campos da resposta seguem a documentação pública da API; confirme-os na homologação
 * (ver docs/integracoes.md). O mapeamento aceita as variações conhecidas.
 *
 * O CPF de fornecedor pessoa física vai à API porque a consulta é obrigação legal do órgão (LGPD, art. 7º, II);
 * a consulta guarda só o resumo das sanções, nunca o CPF.
 */
final class ConsultaCeisCnep
{
    public const CADASTROS = ['ceis', 'cnep'];

    public function habilitada(): bool
    {
        return filled(config('nexplangov.transparencia.chave'));
    }

    /** Consulta os dois cadastros e grava o resultado. Se a API falhar, grava a consulta como indisponível. */
    public function consultar(Fornecedor $f, ?User $u = null): ConsultaImpedimento
    {
        $base = ['orgao_id' => $f->orgao_id, 'fornecedor_id' => $f->id, 'user_id' => $u?->id, 'consultado_em' => now()];

        try {
            [$registros, $bruto] = $this->buscar($f->documentoCompleto());
        } catch (IntegracaoIndisponivel $e) {
            return ConsultaImpedimento::create($base + ['situacao' => 'indisponivel', 'erro' => $e->getMessage()]);
        }

        $vigentes = array_filter(ImpedimentoCadastral::vigentes($registros, today()), [ImpedimentoCadastral::class, 'ehImpeditiva']);
        $assinatura = $registros ? hash('sha256', implode('|', array_map(fn ($r) => $r['cadastro'].':'.$r['id'], $vigentes))) : null;

        // A análise de abrangência vale enquanto as sanções forem as mesmas da consulta em que foi feita.
        $anterior = $assinatura ? ConsultaImpedimento::where('fornecedor_id', $f->id)->where('assinatura_registros', $assinatura)->whereNotNull('ressalva_em')->latest('id')->first() : null;

        return ConsultaImpedimento::create($base + [
            'situacao' => $registros ? 'sancionado' : 'regular',
            'registros' => $registros,
            'assinatura_registros' => $assinatura,
            'hash_resposta' => hash('sha256', $bruto),
            'ressalva_justificativa' => $anterior?->ressalva_justificativa,
            'ressalva_por' => $anterior?->ressalva_por,
            'ressalva_em' => $anterior?->ressalva_em,
        ]);
    }

    /** @return array{0: list<array{id: string, cadastro: string, tipo: string, orgao: string, inicio: ?string, fim: ?string}>, 1: string} */
    private function buscar(string $documento): array
    {
        $cfg = config('nexplangov.transparencia');
        $registros = [];
        $bruto = '';

        try {
            foreach (self::CADASTROS as $cadastro) {
                for ($pagina = 1; $pagina <= 5; $pagina++) {
                    $r = Http::baseUrl($cfg['url'])->timeout($cfg['timeout'])->acceptJson()->withHeaders(['chave-api-dados' => $cfg['chave']])
                        ->retry(2, 500, throw: false)->get("/{$cadastro}", ['codigoSancionado' => $documento, 'pagina' => $pagina]);

                    if (! $r->successful()) {
                        throw new IntegracaoIndisponivel("O Portal da Transparência respondeu com erro {$r->status()} na consulta ao ".strtoupper($cadastro).'.');
                    }

                    $bruto .= $r->body();
                    $lista = $r->json();
                    $lista = is_array($lista) ? ($lista['data'] ?? $lista) : [];

                    foreach ($lista as $l) {
                        if (is_array($l) && ($n = $this->normalizar($l, $cadastro))) {
                            $registros[] = $n;
                        }
                    }

                    if (count($lista) < 15) { // a API devolve até 15 registros por página
                        break;
                    }
                }
            }
        } catch (IntegracaoIndisponivel $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw new IntegracaoIndisponivel('Não foi possível consultar o CEIS e o CNEP agora.');
        }

        return [$registros, $bruto];
    }

    private function normalizar(array $l, string $cadastro): ?array
    {
        $tipo = $l['tipoSancao']['descricaoResumida'] ?? $l['tipoSancao']['descricaoPortal'] ?? (is_string($l['tipoSancao'] ?? null) ? $l['tipoSancao'] : null);

        if (! $tipo) {
            return null;
        }

        return [
            'id' => (string) ($l['id'] ?? md5(json_encode($l))),
            'cadastro' => $cadastro,
            'tipo' => (string) $tipo,
            'orgao' => (string) (is_array($l['orgaoSancionador'] ?? null) ? ($l['orgaoSancionador']['nome'] ?? 'Órgão não informado') : ($l['orgaoSancionador'] ?? 'Órgão não informado')),
            'inicio' => self::data($l['dataInicioSancao'] ?? null),
            'fim' => self::data($l['dataFimSancao'] ?? null),
        ];
    }

    /** A API usa dd/mm/aaaa; aceita também o formato ISO. */
    private static function data(mixed $valor): ?string
    {
        if (! is_string($valor) || trim($valor) === '' || str_contains($valor, 'Sem informação')) {
            return null;
        }

        try {
            return preg_match('#^\d{2}/\d{2}/\d{4}$#', $valor) ? Carbon::createFromFormat('d/m/Y', $valor)->toDateString() : Carbon::parse($valor)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
