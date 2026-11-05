<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Perfis e permissões do sistema. Pode rodar quantas vezes quiser (é idempotente)
 * e roda a cada instalação ou atualização.
 */
class PerfisSeeder extends Seeder
{
    /** @var array<string, string> */
    public const PERMISSOES = [
        'orgaos.gerenciar' => 'Cadastrar e alterar órgãos',
        'master.ver' => 'Painel master da NexMind (visão de todos os órgãos)',
        'catalogo.ver' => 'Consultar o catálogo CATMAT/CATSER',
        'ia.usar' => 'Usar a NexMind IA para sugerir e revisar peças',
        'capacitacao.ver' => 'Ver e registrar a própria capacitação',
        'capacitacao.gerenciar' => 'Registrar a capacitação dos agentes do órgão',
        'exportacoes.gerar' => 'Gerar a remessa ao tribunal de contas',
        'devolucao.gerar' => 'Gerar e baixar o pacote de devolução de todos os dados do órgão',
        'governanca.ver' => 'Ver o painel de governança das contratações (art. 11)',
        'unidades.ver' => 'Ver o organograma e as unidades',
        'unidades.gerenciar' => 'Cadastrar e alterar unidades',
        'usuarios.ver' => 'Ver os usuários do órgão',
        'usuarios.gerenciar' => 'Cadastrar usuários e definir perfis',
        'auditoria.ver' => 'Consultar a trilha de auditoria',
        'painel.ver' => 'Ver o painel',
        'pca.ver' => 'Ver o Plano de Contratações Anual',
        'pca.gerenciar' => 'Montar o PCA e incluir itens',
        'pca.aprovar' => 'Aprovar o PCA (autoridade competente)',
        'demandas.ver' => 'Ver documentos de formalização de demanda (DFD)',
        'demandas.criar' => 'Registrar demandas (DFD)',
        'processos.ver' => 'Ver processos de contratação',
        'processos.tramitar' => 'Enviar, receber e arquivar processos entre unidades',
        'processos.gerenciar' => 'Instruir processos: ETP, TR, riscos e pesquisa de preços',
        'processos.parecer' => 'Emitir parecer jurídico (art. 53)',
        'processos.autorizar' => 'Autorizar, homologar, revogar ou anular (autoridade)',
        'licitacao.conduzir' => 'Conduzir a licitação: propostas, lances, julgamento, habilitação e adjudicação',
        'irp.ver' => 'Ver intenções de registro de preços (art. 86)',
        'irp.manifestar' => 'Manifestar necessidade em uma IRP',
        'irp.gerenciar' => 'Abrir, decidir e consolidar IRP (órgão gerenciador)',
        'modelos.gerenciar' => 'Gerenciar a biblioteca de modelos de documentos',
        'fluxos.gerenciar' => 'Configurar fluxos de documentos e prazos',
        'contratos.ver' => 'Ver contratos e atas',
        'contratos.gerenciar' => 'Cadastrar contratos, aditivos e apostilamentos; aprovar requisições, emitir ordens e receber (art. 140)',
        'requisicoes.ver' => 'Ver requisições de fornecimento sobre atas',
        'requisicoes.criar' => 'Requisitar fornecimento do saldo de uma ata',
        'fornecedores.ver' => 'Ver fornecedores',
        'fornecedores.gerenciar' => 'Cadastrar fornecedores e sanções',
        'pagamentos.gerenciar' => 'Liquidar e registrar pagamentos na ordem cronológica (art. 141)',
        'sancoes.conduzir' => 'Instaurar e conduzir processo de sanção (arts. 155 a 163)',
        'pncp.enviar' => 'Enviar e retificar publicações no PNCP',
        'lgpd.gerenciar' => 'Atender titulares e manter o registro de tratamento (LGPD)',
    ];

    /** @var array<string, list<string>> */
    public const PERFIS = [
        'administrador-geral' => ['*'],
        'administrador-orgao' => ['unidades.ver', 'unidades.gerenciar', 'usuarios.ver', 'usuarios.gerenciar', 'auditoria.ver', 'painel.ver', 'pca.ver', 'demandas.ver', 'processos.ver', 'processos.tramitar', 'contratos.ver', 'fornecedores.ver', 'lgpd.gerenciar', 'modelos.gerenciar', 'fluxos.gerenciar', 'irp.ver', 'irp.manifestar', 'irp.gerenciar', 'requisicoes.ver', 'requisicoes.criar', 'catalogo.ver', 'capacitacao.ver', 'capacitacao.gerenciar', 'governanca.ver', 'exportacoes.gerar', 'devolucao.gerar'],
        'autoridade' => ['unidades.ver', 'painel.ver', 'pca.ver', 'pca.aprovar', 'demandas.ver', 'processos.ver', 'processos.tramitar', 'processos.autorizar', 'contratos.ver', 'fornecedores.ver', 'irp.ver', 'catalogo.ver', 'capacitacao.ver', 'governanca.ver'],
        'agente-contratacao' => ['unidades.ver', 'painel.ver', 'pca.ver', 'demandas.ver', 'processos.ver', 'processos.tramitar', 'processos.gerenciar', 'contratos.ver', 'fornecedores.ver', 'sancoes.conduzir', 'fornecedores.gerenciar', 'pncp.enviar', 'licitacao.conduzir', 'irp.ver', 'irp.manifestar', 'irp.gerenciar', 'requisicoes.ver', 'requisicoes.criar', 'catalogo.ver', 'ia.usar', 'capacitacao.ver'],
        'planejamento' => ['unidades.ver', 'usuarios.ver', 'painel.ver', 'pca.ver', 'pca.gerenciar', 'demandas.ver', 'demandas.criar', 'processos.ver', 'processos.tramitar', 'processos.gerenciar', 'contratos.ver', 'fornecedores.ver', 'fornecedores.gerenciar', 'pncp.enviar', 'modelos.gerenciar', 'fluxos.gerenciar', 'irp.ver', 'irp.manifestar', 'irp.gerenciar', 'requisicoes.ver', 'requisicoes.criar', 'catalogo.ver', 'ia.usar', 'capacitacao.ver', 'governanca.ver', 'exportacoes.gerar'],
        'juridico' => ['painel.ver', 'pca.ver', 'demandas.ver', 'processos.ver', 'processos.tramitar', 'processos.parecer', 'contratos.ver', 'fornecedores.ver', 'modelos.gerenciar', 'irp.ver', 'catalogo.ver', 'ia.usar', 'capacitacao.ver'],
        'gestor-contrato' => ['painel.ver', 'processos.ver', 'processos.tramitar', 'contratos.ver', 'contratos.gerenciar', 'fornecedores.ver', 'sancoes.conduzir', 'pagamentos.gerenciar', 'pncp.enviar', 'irp.ver', 'irp.manifestar', 'requisicoes.ver', 'requisicoes.criar', 'catalogo.ver', 'ia.usar', 'capacitacao.ver'],
        'requisitante' => ['painel.ver', 'pca.ver', 'demandas.ver', 'demandas.criar', 'irp.ver', 'irp.manifestar', 'requisicoes.ver', 'requisicoes.criar', 'catalogo.ver'],
        'auditor' => ['unidades.ver', 'usuarios.ver', 'auditoria.ver', 'painel.ver', 'pca.ver', 'demandas.ver', 'processos.ver', 'processos.tramitar', 'contratos.ver', 'fornecedores.ver', 'irp.ver', 'catalogo.ver', 'capacitacao.ver', 'governanca.ver', 'exportacoes.gerar'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSOES) as $nome) {
            Permission::findOrCreate($nome, 'web');
        }

        foreach (self::PERFIS as $perfil => $permissoes) {
            $role = Role::findOrCreate($perfil, 'web');
            $role->syncPermissions($permissoes === ['*'] ? array_keys(self::PERMISSOES) : $permissoes);
        }

        assert(array_keys(self::PERFIS) === array_keys(User::PERFIS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
