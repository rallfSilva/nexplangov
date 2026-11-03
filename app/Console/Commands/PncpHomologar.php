<?php

namespace App\Console\Commands;

use App\Jobs\EnviarAoPncp;
use App\Models\EnvioPncp;
use App\Models\Orgao;
use App\Models\Processo;
use App\Models\Unidade;
use App\Pncp\FilaPncp;
use App\Pncp\PncpCliente;
use App\Support\Documento;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Conferência da integração com o PNCP antes de o órgão ir para produção.
 * Sem --ciclo, só lê (login, cadastro do órgão e das unidades). Com --ciclo, publica de verdade uma compra
 * no ambiente de treinamento do PNCP (treina.pncp.gov.br) e mostra o retorno. Nunca roda o ciclo em produção.
 */
#[Signature('nexplangov:pncp-homologar {--orgao= : Sigla do órgão} {--ciclo : Publica uma compra de teste no ambiente de treinamento do PNCP} {--processo= : Id do processo usado no ciclo}')]
#[Description('Confere login, órgão e unidades no PNCP e, com --ciclo, faz uma publicação de teste em homologação')]
class PncpHomologar extends Command
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $linhas = [];

    public function handle(PncpCliente $cliente, FilaPncp $fila): int
    {
        $orgao = Orgao::where('sigla', $this->option('orgao'))->first();
        if (! $orgao) {
            $this->error('Informe o órgão: --orgao=SIGLA');

            return self::FAILURE;
        }

        $ambiente = $orgao->pncp_ambiente ?: 'homologacao';
        $cnpj = Documento::digitos((string) $orgao->cnpj);
        $this->info("Órgão {$orgao->sigla} · ambiente {$ambiente} · {$cliente->urlBase($ambiente)}");

        $ok = $this->conferir('CNPJ do órgão', fn () => Documento::cnpjValido($cnpj) ? $cnpj : throw new \RuntimeException('CNPJ inválido no cadastro.'));
        $ok = $this->conferir('Credenciais no .env', fn () => $cliente->credenciaisConfiguradas() ? 'PNCP_LOGIN e PNCP_SENHA preenchidos' : throw new \RuntimeException('Preencha PNCP_LOGIN e PNCP_SENHA.')) && $ok;
        $ok = $ok && $this->conferir('Login na API', fn () => $cliente->token($ambiente, renovar: true) ? 'token recebido (vale 1 hora)' : '');

        if ($ok) {
            $ok = $this->conferir('Órgão cadastrado no PNCP', function () use ($cliente, $ambiente, $cnpj) {
                $r = $cliente->consultar($ambiente, "/v1/orgaos/{$cnpj}");

                return $r->successful() ? ($r->json('razaoSocial') ?? 'encontrado') : throw new \RuntimeException("HTTP {$r->status()}: peça ao gestor do PNCP o cadastro do órgão.");
            }) && $ok;

            $ok = $this->conferir('Unidades compradoras', function () use ($cliente, $ambiente, $cnpj, $orgao) {
                $r = $cliente->consultar($ambiente, "/v1/orgaos/{$cnpj}/unidades");
                if (! $r->successful()) {
                    throw new \RuntimeException("HTTP {$r->status()} ao listar unidades.");
                }
                $noPncp = collect($r->json() ?? [])->pluck('codigoUnidade')->map(fn ($c) => (string) $c)->all();
                $locais = Unidade::withoutGlobalScopes()->where('orgao_id', $orgao->id)->whereNotNull('codigo_unidade_pncp')->pluck('codigo_unidade_pncp')->all();
                $faltam = array_values(array_diff($locais, $noPncp));
                if ($locais === []) {
                    throw new \RuntimeException('Nenhuma unidade tem código de unidade compradora. Preencha em Unidades.');
                }

                return $faltam === [] ? count($locais).' unidade(s) conferida(s)' : throw new \RuntimeException('Não cadastradas no PNCP: '.implode(', ', $faltam));
            }) && $ok;
        }

        $presos = EnvioPncp::withoutGlobalScopes()->where('orgao_id', $orgao->id)->whereIn('estado', ['erro', 'pendente'])->count();
        $this->linhas[] = ['Envios com erro ou na fila', $presos === 0 ? 'ok' : 'atenção', (string) $presos];

        if ($this->option('ciclo')) {
            $ok = $ok && $this->ciclo($orgao, $ambiente, $fila);
        }

        $this->table(['Conferência', 'Resultado', 'Detalhe'], $this->linhas);

        if (! $ok) {
            $this->error('Há pendências. Corrija e rode de novo.');

            return self::FAILURE;
        }

        $this->info($this->option('ciclo') ? 'Ciclo de teste concluído no ambiente de treinamento.' : 'Conferência concluída. Para publicar um teste: --ciclo --processo=ID');

        return self::SUCCESS;
    }

    private function ciclo(Orgao $orgao, string $ambiente, FilaPncp $fila): bool
    {
        if ($ambiente !== 'homologacao') {
            $this->linhas[] = ['Ciclo de teste', 'recusado', 'Só no ambiente de homologação. Este órgão está em produção.'];

            return false;
        }

        $processo = Processo::withoutGlobalScopes()->where('orgao_id', $orgao->id)->find($this->option('processo'));
        if (! $processo) {
            $this->linhas[] = ['Ciclo de teste', 'falhou', 'Informe --processo=ID de um processo do órgão.'];

            return false;
        }

        $envio = $fila->agendar($processo, 'compra', 'inserir');
        if ($envio->estado === 'erro') {
            $this->linhas[] = ['Montagem da compra', 'falhou', $envio->erro];

            return false;
        }

        EnviarAoPncp::dispatchSync($envio->id);
        $envio->refresh();
        $publicado = $envio->estado === 'publicado';
        $this->linhas[] = ['Publicação da compra', $publicado ? 'ok' : 'falhou', $publicado ? 'Referência '.$processo->refresh()->numero_controle_pncp : (string) $envio->erro];

        return $publicado;
    }

    private function conferir(string $nome, callable $teste): bool
    {
        try {
            $this->linhas[] = [$nome, 'ok', (string) $teste()];

            return true;
        } catch (Throwable $e) {
            $this->linhas[] = [$nome, 'falhou', $e->getMessage()];

            return false;
        }
    }
}
