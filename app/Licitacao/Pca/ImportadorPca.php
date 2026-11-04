<?php

namespace App\Licitacao\Pca;

use App\Models\ImportacaoPca;
use App\Models\ItemPca;
use App\Models\PlanoContratacao;
use App\Models\Unidade;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Importa itens do PCA de uma planilha em duas etapas: analisar (lê, confere cada linha com as mesmas
 * regras da tela e aponta duplicidades) e confirmar (grava só as linhas válidas, em lotes, numa transação).
 * Nada entra no plano sem a pré-visualização; plano aprovado só recebe itens por revisão (art. 12, § 1º).
 */
final class ImportadorPca
{
    private const LOTE = 200;

    public function analisar(PlanoContratacao $plano, UploadedFile $arquivo, User $usuario): ImportacaoPca
    {
        if (! $plano->editavel()) {
            throw new RuntimeException('O PCA aprovado não recebe itens diretamente. Abra uma revisão do plano.');
        }

        $extensao = strtolower($arquivo->getClientOriginalExtension());
        $lidas = PlanilhaPca::ler($arquivo->getRealPath(), $extensao === 'xlsx' ? 'xlsx' : 'csv');
        if ($lidas === []) {
            throw new RuntimeException('A planilha não tem itens abaixo do cabeçalho.');
        }

        $unidades = Unidade::where('orgao_id', $plano->orgao_id)->pluck('id', 'sigla')
            ->mapWithKeys(fn ($id, $sigla) => [Str::upper($sigla) => $id])->all();
        $existentes = $plano->itens()->get()->mapWithKeys(fn (ItemPca $i) => [PlanilhaPca::chaveDoItem($i) => $i->numero])->all();
        $vistas = [];
        $linhas = [];

        foreach ($lidas as $lida) {
            ['dados' => $dados, 'erros' => $erros] = PlanilhaPca::normalizar($lida['valores'], $unidades);

            $v = Validator::make($dados, RegrasItemPca::regras($plano->orgao_id, $dados['material_servico']), RegrasItemPca::mensagens(), self::nomesDosCampos());
            $erros = array_values(array_unique([...$erros, ...$v->errors()->all()]));

            $chave = PlanilhaPca::chaveDuplicidade($dados['codigo_catalogo'], $dados['descricao'], $dados['unidade_fornecimento']);
            $situacao = $erros === [] ? 'ok' : 'erro';
            if ($situacao === 'ok' && isset($existentes[$chave])) {
                $situacao = 'duplicada';
                $erros[] = "Já existe no plano (item {$existentes[$chave]}).";
            } elseif ($situacao === 'ok' && isset($vistas[$chave])) {
                $situacao = 'duplicada';
                $erros[] = "Repete a linha {$vistas[$chave]} da planilha.";
            }
            if ($situacao === 'ok') {
                $vistas[$chave] = $lida['linha'];
            }

            $linhas[] = ['linha' => $lida['linha'], 'situacao' => $situacao, 'motivos' => $erros, 'dados' => $dados];
        }

        $conta = array_count_values(array_column($linhas, 'situacao'));

        return ImportacaoPca::create([
            'orgao_id' => $plano->orgao_id,
            'plano_contratacao_id' => $plano->id,
            'user_id' => $usuario->id,
            'arquivo_nome' => mb_substr($arquivo->getClientOriginalName(), 0, 160),
            'arquivo_hash' => hash_file('sha256', $arquivo->getRealPath()),
            'linhas' => $linhas,
            'total_linhas' => count($linhas),
            'validas' => $conta['ok'] ?? 0,
            'com_erro' => $conta['erro'] ?? 0,
            'duplicadas' => $conta['duplicada'] ?? 0,
        ]);
    }

    /** Grava as linhas válidas no plano. Devolve quantos itens entraram. */
    public function confirmar(ImportacaoPca $imp): int
    {
        $plano = $imp->plano;
        if (! $imp->pendente()) {
            throw new RuntimeException('Esta importação já foi concluída ou cancelada.');
        }
        if (! $plano->editavel()) {
            throw new RuntimeException('O PCA foi aprovado depois da pré-visualização. Abra uma revisão para incluir itens.');
        }

        return DB::transaction(function () use ($imp, $plano) {
            // Revalida a duplicidade: o plano pode ter mudado depois da pré-visualização.
            $existentes = $plano->itens()->lockForUpdate()->get()->map(fn (ItemPca $i) => PlanilhaPca::chaveDoItem($i))->flip()->all();
            $numero = $plano->proximoNumeroItem();
            $novos = 0;

            foreach (array_chunk(array_filter($imp->linhas, fn ($l) => $l['situacao'] === 'ok'), self::LOTE) as $lote) {
                foreach ($lote as $l) {
                    $d = $l['dados'];
                    $chave = PlanilhaPca::chaveDuplicidade($d['codigo_catalogo'], $d['descricao'], $d['unidade_fornecimento']);
                    if (isset($existentes[$chave])) {
                        continue;
                    }
                    $plano->itens()->create($d + ['orgao_id' => $plano->orgao_id, 'numero' => $numero++]);
                    $existentes[$chave] = true;
                    $novos++;
                }
            }

            $imp->forceFill(['situacao' => 'confirmada', 'importadas' => $novos, 'confirmada_em' => now()])->save();
            Auditoria::registrar('importacao', $plano, "{$novos} item(ns) importado(s) da planilha \"{$imp->arquivo_nome}\" para o PCA {$plano->ano}");

            return $novos;
        });
    }

    public function cancelar(ImportacaoPca $imp): void
    {
        if ($imp->pendente()) {
            $imp->forceFill(['situacao' => 'cancelada'])->save();
        }
    }

    /** @return array<string, string> */
    private static function nomesDosCampos(): array
    {
        return [
            'material_servico' => 'tipo', 'categoria' => 'categoria', 'codigo_catalogo' => 'código CATMAT/CATSER', 'descricao' => 'descrição',
            'unidade_fornecimento' => 'unidade de fornecimento', 'quantidade' => 'quantidade', 'valor_unitario_estimado' => 'valor unitário',
            'unidade_id' => 'unidade requisitante', 'data_desejada' => 'data desejada', 'prioridade' => 'prioridade', 'natureza_despesa' => 'natureza da despesa',
        ];
    }
}
