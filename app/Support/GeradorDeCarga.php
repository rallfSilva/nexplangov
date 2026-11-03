<?php

namespace App\Support;

use App\Models\Contrato;
use App\Models\Demanda;
use App\Models\Fornecedor;
use App\Models\ItemPca;
use App\Models\Orgao;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gera volume de dados fictícios num órgão para teste de carga e de desempenho das telas.
 * Todos os nomes, CNPJs (série 99.9xx) e valores são inventados. Nunca roda em produção.
 */
final class GeradorDeCarga
{
    private const OBJETOS = [
        'Aquisição de material de expediente', 'Aquisição de gêneros alimentícios para a merenda escolar',
        'Serviço de manutenção preventiva de veículos', 'Aquisição de medicamentos da atenção básica',
        'Serviço de vigilância patrimonial', 'Aquisição de mobiliário escolar', 'Serviço de locação de impressoras',
        'Aquisição de equipamentos de informática', 'Serviço de transporte escolar', 'Aquisição de material de limpeza',
    ];

    /** @return array{fornecedores:int, itens_pca:int, demandas:int, processos:int, contratos:int} */
    public function gerar(Orgao $orgao, int $processos, ?User $responsavel = null): array
    {
        if (app()->isProduction()) {
            throw new \LogicException('A carga fictícia não roda em produção.');
        }

        return DB::transaction(function () use ($orgao, $processos, $responsavel) {
            $unidades = Unidade::withoutGlobalScopes()->where('orgao_id', $orgao->id)->get();
            if ($unidades->isEmpty()) {
                $unidades = collect([Unidade::withoutGlobalScopes()->create(['orgao_id' => $orgao->id, 'sigla' => 'CARGA', 'nome' => 'Unidade de carga (fictícia)', 'tipo' => 'secretaria_adjunta'])]);
            }
            $responsavel ??= User::where('orgao_id', $orgao->id)->first();

            $base = (int) Fornecedor::withoutGlobalScopes()->where('orgao_id', $orgao->id)->count();
            $qtdFornecedores = max(5, intdiv($processos, 4));
            $fornecedores = [];
            for ($i = 0; $i < $qtdFornecedores; $i++) {
                $n = $base + $i;
                $fornecedores[] = Fornecedor::create([
                    'orgao_id' => $orgao->id, 'tipo_pessoa' => 'PJ', 'documento' => self::cnpj($orgao->id, $n),
                    'razao_social' => 'Fornecedor de Carga '.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT).' Ltda (fictícia)',
                    'porte' => ['ME', 'EPP', 'DEMAIS'][$n % 3], 'municipio' => 'Vale Verde', 'uf' => 'RR', 'email' => "carga{$n}@exemplo.com.br",
                ]);
            }

            $ano = (int) now()->year;
            $pca = PlanoContratacao::withoutGlobalScopes()->firstOrCreate(['orgao_id' => $orgao->id, 'ano' => $ano + 1], ['situacao' => 'rascunho']);
            $numeroItem = (int) ItemPca::withoutGlobalScopes()->where('plano_contratacao_id', $pca->id)->max('numero');
            $numeroProcesso = $this->ultimoNumero(Processo::class, $orgao, $ano);
            $numeroContrato = $this->ultimoNumero(Contrato::class, $orgao, $ano);
            $numeroDemanda = $this->ultimoNumero(Demanda::class, $orgao, $ano);
            $contratos = 0;

            for ($i = 0; $i < $processos; $i++) {
                $unidade = $unidades[$i % $unidades->count()];
                $objeto = self::OBJETOS[$i % count(self::OBJETOS)].' (carga '.($i + 1).')';
                $servico = str_starts_with($objeto, 'Serviço');
                $valor = 5000 + (($i * 7919) % 900000);

                $item = ItemPca::create([
                    'orgao_id' => $orgao->id, 'plano_contratacao_id' => $pca->id, 'unidade_id' => $unidade->id, 'numero' => ++$numeroItem,
                    'material_servico' => $servico ? 'S' : 'M', 'categoria' => $servico ? 6 : 2, 'descricao' => $objeto,
                    'unidade_fornecimento' => 'UN', 'quantidade' => 10, 'valor_unitario_estimado' => round($valor / 10, 2),
                    'data_desejada' => ($ano + 1).'-0'.(($i % 9) + 1).'-10', 'prioridade' => ($i % 3) + 1,
                ]);

                if ($i % 2 === 0 && $responsavel) {
                    Demanda::create([
                        'orgao_id' => $orgao->id, 'unidade_id' => $unidade->id, 'item_pca_id' => $item->id, 'solicitante_id' => $responsavel->id,
                        'numero' => str_pad((string) ++$numeroDemanda, 4, '0', STR_PAD_LEFT), 'ano' => $ano, 'objeto' => $objeto,
                        'justificativa' => 'Demanda fictícia gerada para teste de carga do sistema.', 'quantidade' => 10, 'valor_estimado' => $valor,
                        'prioridade' => ($i % 3) + 1, 'situacao' => 'enviada',
                    ]);
                }

                $situacao = ['planejamento', 'analise_juridica', 'publicado', 'homologado', 'contratado'][$i % 5];
                $p = Processo::create([
                    'orgao_id' => $orgao->id, 'unidade_id' => $unidade->id, 'responsavel_id' => $responsavel?->id,
                    'numero' => str_pad((string) ++$numeroProcesso, 3, '0', STR_PAD_LEFT), 'ano' => $ano, 'objeto' => $objeto,
                    'modalidade' => $i % 4 === 0 ? 8 : 6, 'amparo_legal' => $i % 4 === 0 ? 19 : 1, 'criterio_julgamento' => 1,
                    'modo_disputa' => $i % 4 === 0 ? 4 : 1, 'categoria' => $servico ? 6 : 2,
                ]);
                $p->forceFill(['situacao' => $situacao, 'valor_estimado' => $valor, 'valor_homologado' => in_array($situacao, ['homologado', 'contratado'], true) ? round($valor * 0.93, 2) : null])->save();
                $p->itens()->create([
                    'orgao_id' => $orgao->id, 'item_pca_id' => $item->id, 'numero' => 1, 'material_servico' => $servico ? 'S' : 'M',
                    'descricao' => $objeto, 'unidade_medida' => 'UN', 'quantidade' => 10, 'tipo_beneficio' => 3,
                ]);

                if ($situacao === 'contratado' && $responsavel) {
                    $inicio = today()->subDays(($i * 13) % 300);
                    Contrato::create([
                        'orgao_id' => $orgao->id, 'processo_id' => $p->id, 'fornecedor_id' => $fornecedores[$i % count($fornecedores)]->id,
                        'gestor_id' => $responsavel->id, 'fiscal_id' => $responsavel->id,
                        'numero' => str_pad((string) ++$numeroContrato, 3, '0', STR_PAD_LEFT), 'ano' => $ano, 'tipo' => 1, 'objeto' => $objeto,
                        'categoria' => $servico ? 6 : 2, 'servico_continuo' => $servico, 'valor_inicial' => $p->valor_homologado, 'valor_global' => $p->valor_homologado,
                        'assinatura' => $inicio, 'vigencia_inicio' => $inicio, 'vigencia_fim' => $inicio->copy()->addYear(), 'situacao' => 'vigente',
                    ]);
                    $contratos++;
                }
            }

            return ['fornecedores' => $qtdFornecedores, 'itens_pca' => $processos, 'demandas' => intdiv($processos + 1, 2), 'processos' => $processos, 'contratos' => $contratos];
        });
    }

    /** Maior número já usado no ano (a numeração do órgão pode ter lacunas e zeros à esquerda). */
    private function ultimoNumero(string $modelo, Orgao $orgao, int $ano): int
    {
        return (int) $modelo::withoutGlobalScopes()->where('orgao_id', $orgao->id)->where('ano', $ano)
            ->max(DB::raw("CAST(NULLIF(regexp_replace(numero, '\\D', '', 'g'), '') AS INTEGER)"));
    }

    /** CNPJ fictício da série 99.9xx com dígitos verificadores válidos. */
    public static function cnpj(int $orgaoId, int $n, string $prefixo = '999'): string
    {
        $base = $prefixo.str_pad((string) ($orgaoId % 100), 2, '0', STR_PAD_LEFT).str_pad((string) ($n % 1000), 3, '0', STR_PAD_LEFT).'0001';
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $pesos) {
            $soma = 0;
            foreach ($pesos as $k => $peso) {
                $soma += (int) $base[$k] * $peso;
            }
            $resto = $soma % 11;
            $base .= $resto < 2 ? '0' : (string) (11 - $resto);
        }

        return substr($base, 0, 2).'.'.substr($base, 2, 3).'.'.substr($base, 5, 3).'/'.substr($base, 8, 4).'-'.substr($base, 12, 2);
    }
}
