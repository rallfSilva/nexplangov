<?php

namespace Tests\Feature\Lei14133;

use App\Licitacao\TiposDocumento;
use App\Models\Fornecedor;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\ValoresLimiteSeeder;

/** Monta um órgão com todos os perfis e um processo pronto para instruir. */
trait CenarioContratacao
{
    protected Orgao $orgao;

    protected Unidade $unidade;

    /** @var array<string, User> */
    protected array $u = [];

    protected function montarCenario(): void
    {
        $this->semearPerfis();
        $this->seed(ValoresLimiteSeeder::class);
        $this->orgao = Orgao::factory()->create(['sigla' => 'PMX', 'cnpj' => '11.222.333/0001-81']);
        $this->unidade = Unidade::factory()->for($this->orgao)->create(['sigla' => 'SEMAD', 'codigo_unidade_pncp' => '1']);

        foreach (['planejamento', 'agente-contratacao', 'juridico', 'autoridade', 'gestor-contrato', 'requisitante', 'auditor'] as $perfil) {
            $this->u[$perfil] = $this->usuario($perfil, $this->orgao);
        }
        $this->u['fiscal'] = $this->usuario('gestor-contrato', $this->orgao);
    }

    protected function processo(array $dados = [], string $situacao = 'planejamento'): Processo
    {
        $p = Processo::create($dados + [
            'orgao_id' => $this->orgao->id,
            'unidade_id' => $this->unidade->id,
            'numero' => (string) random_int(100, 99999),
            'ano' => 2026,
            'objeto' => 'Aquisição de material de expediente para as secretarias.',
            'modalidade' => 6,
            'amparo_legal' => 1,
            'criterio_julgamento' => 1,
            'modo_disputa' => 1,
            'categoria' => 2,
            'abertura_propostas_em' => '2026-12-01 08:00:00',
            'encerramento_propostas_em' => '2026-12-10 08:00:00',
        ]);
        $p->forceFill(['situacao' => $situacao])->save();

        return $p;
    }

    protected function fornecedor(string $cnpj = '99.000.333/0001-88'): Fornecedor
    {
        return Fornecedor::create(['orgao_id' => $this->orgao->id, 'tipo_pessoa' => 'PJ', 'documento' => $cnpj, 'razao_social' => 'Fornecedor Teste '.$cnpj]);
    }

    /** Preenche e conclui uma peça da instrução pela tela, como o usuário faria. */
    protected function concluirDocumento(Processo $p, string $tipo, User $quem, array $conteudo = []): void
    {
        $campos = [];
        foreach (TiposDocumento::get($tipo)['campos'] as $campo => $d) {
            $campos[$campo] = $conteudo[$campo] ?? ($d['obrigatorio'] ? 'Conteúdo de teste preenchido.' : '');
        }

        $this->actingAs($quem)->post("/processos/{$p->id}/documentos/{$tipo}", ['conteudo' => $campos, 'acao' => 'concluir'])->assertRedirect();
    }

    protected function itemComPreco(Processo $p, float $valor = 10.0, float $quantidade = 100): void
    {
        $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/itens", [
            'descricao' => 'Caneta esferográfica azul', 'material_servico' => 'M', 'unidade_medida' => 'UN', 'quantidade' => (string) $quantidade, 'tipo_beneficio' => 4,
        ])->assertRedirect();

        $item = $p->itens()->latest('id')->first();

        foreach ([$valor, $valor * 1.05, $valor * 0.95] as $v) {
            $this->actingAs($this->u['planejamento'])->post("/processos/{$p->id}/itens/{$item->id}/cotacoes", [
                'fonte' => 'painel_oficial', 'referencia' => 'Painel de Preços', 'valor_unitario' => number_format($v, 2, ',', '.'), 'data_cotacao' => today()->subDays(5)->format('Y-m-d'),
            ])->assertRedirect();
        }
    }
}
