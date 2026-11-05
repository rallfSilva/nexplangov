<?php

namespace Database\Seeders;

use App\Models\Contrato;
use App\Models\Demanda;
use App\Models\DocumentoProcesso;
use App\Models\EventoProcesso;
use App\Models\Fornecedor;
use App\Models\ItemPca;
use App\Models\OperacaoTratamento;
use App\Models\Orgao;
use App\Models\PlanoContratacao;
use App\Models\Processo;
use App\Models\SolicitacaoTitular;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Dados de demonstração, todos fictícios (órgãos, pessoas, CNPJs e valores). Nunca roda em produção.
 * Senha de todos os usuários de demonstração: NexPlan@demo2026
 */
class DemoSeeder extends Seeder
{
    public const SENHA = 'NexPlan@demo2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoSeeder ignorado em produção.');

            return;
        }

        $this->call(ValoresLimiteSeeder::class);

        $pm = Orgao::firstOrCreate(['sigla' => 'PMVV'], [
            'nome' => 'Prefeitura Municipal de Vale Verde (fictícia)',
            'cnpj' => '99.000.111/0001-65',
            'esfera' => 'municipal',
            'poder' => 'executivo',
            'natureza_juridica' => 1031,
            'uf' => 'RR',
            'municipio' => 'Vale Verde',
        ]);

        $camara = Orgao::firstOrCreate(['sigla' => 'CMVV'], [
            'nome' => 'Câmara Municipal de Vale Verde (fictícia, para testar o isolamento)',
            'cnpj' => '99.000.222/0001-71',
            'esfera' => 'municipal',
            'poder' => 'legislativo',
            'natureza_juridica' => 1066,
            'uf' => 'RR',
            'municipio' => 'Vale Verde',
        ]);

        foreach ([$pm, $camara] as $orgaoDemo) {
            ModelosPadrao::instalarPara($orgaoDemo);
            FluxosPadrao::instalarPara($orgaoDemo);
        }

        $u = [];
        foreach ([
            ['admin@nexplangov.local', 'Administrador do Sistema', null, 'administrador-geral'],
            ['admin.pmvv@nexplangov.local', 'Helena Duarte Campos', $pm, 'administrador-orgao'],
            ['prefeito@nexplangov.local', 'Rodrigo Albuquerque Sá', $pm, 'autoridade'],
            ['agente@nexplangov.local', 'Patrícia Moura Lins', $pm, 'agente-contratacao'],
            ['planejamento@nexplangov.local', 'Marcos Vinícius Teles', $pm, 'planejamento'],
            ['juridico@nexplangov.local', 'Luana Ferraz Bastos', $pm, 'juridico'],
            ['gestor@nexplangov.local', 'Caio Henrique Prado', $pm, 'gestor-contrato'],
            ['fiscal@nexplangov.local', 'Beatriz Nogueira Lima', $pm, 'gestor-contrato'],
            ['educacao@nexplangov.local', 'Ana Beatriz Martins', $pm, 'requisitante'],
            ['controle@nexplangov.local', 'Jorge Henrique Pacheco', $pm, 'auditor'],
            ['admin.cmvv@nexplangov.local', 'Carla Fernandes Rocha', $camara, 'administrador-orgao'],
        ] as [$email, $nome, $orgao, $perfil]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $nome,
                'orgao_id' => $orgao?->id,
                'password' => self::SENHA,
                'email_verified_at' => now(),
            ]);
            $user->syncRoles([$perfil]);
            $u[$perfil.($perfil === 'gestor-contrato' && isset($u['gestor-contrato']) ? '2' : '')] = $user;
        }

        $gab = $this->unidade($pm, 'GAB', 'Gabinete do Prefeito', 'gabinete', null, $u['autoridade'], '1');
        $semad = $this->unidade($pm, 'SEMAD', 'Secretaria Municipal de Administração', 'secretaria_adjunta', $gab, $u['planejamento'], '2');
        $this->unidade($pm, 'PGM', 'Procuradoria-Geral do Município', 'assessoria', $gab, $u['juridico'], null);
        $semed = $this->unidade($pm, 'SEMED', 'Secretaria Municipal de Educação', 'secretaria_adjunta', $gab, $u['requisitante'], '3');
        $semus = $this->unidade($pm, 'SEMUS', 'Secretaria Municipal de Saúde', 'secretaria_adjunta', $gab, null, '4');
        $this->unidade($camara, 'MESA', 'Mesa Diretora', 'gabinete', null, null, '1');

        if (Processo::withoutGlobalScopes()->where('orgao_id', $pm->id)->exists()) {
            return; // já semeado
        }

        $f = [];
        foreach ([
            ['99.000.333/0001-88', 'Papelaria Horizonte Ltda (fictícia)', 'EPP', 'Vale Verde'],
            ['99.000.444/0001-94', 'Distribuidora Norte Escolar Ltda (fictícia)', 'ME', 'Boa Vista'],
            ['99.000.555/0001-09', 'Tecnologia Aurora S.A. (fictícia)', 'DEMAIS', 'Manaus'],
            ['99.000.666/0001-07', 'Limpeza Serra Azul Serviços Ltda (fictícia)', 'EPP', 'Vale Verde'],
            ['99.000.777/0001-13', 'Comercial Rio Claro Ltda (fictícia)', 'ME', 'Boa Vista'],
        ] as [$cnpj, $nome, $porte, $cidade]) {
            $f[] = Fornecedor::create(['orgao_id' => $pm->id, 'tipo_pessoa' => 'PJ', 'documento' => $cnpj, 'razao_social' => $nome, 'porte' => $porte, 'municipio' => $cidade, 'uf' => $cidade === 'Manaus' ? 'AM' : 'RR', 'email' => 'contato@exemplo.com.br']);
        }
        $f[4]->sancoes()->create(['orgao_id' => $pm->id, 'tipo' => 'impedimento', 'inicio' => today()->subMonths(2), 'fim' => today()->addMonths(10), 'processo' => 'PAR 004/2026', 'fundamento' => 'Entrega de material diverso do contratado, sem substituição no prazo (art. 155, IV, e art. 156, III).']);

        // PCA 2026 aprovado e PCA 2027 em elaboração
        $pca26 = PlanoContratacao::create(['orgao_id' => $pm->id, 'ano' => 2026, 'situacao' => 'aprovado']);
        $pca26->forceFill(['aprovado_por' => $u['autoridade']->id, 'aprovado_em' => '2025-12-15 10:00:00'])->save();
        $itens26 = [
            ['M', 2, '461543', 'Papel A4 75 g/m², resma com 500 folhas', 'RESMA', 4000, 27.5, $semad, 1, '3.3.90.30.16'],
            ['M', 2, '150627', 'Kit de material escolar para o ensino fundamental', 'KIT', 3200, 48.9, $semed, 1, '3.3.90.30.22'],
            ['S', 6, '25119', 'Serviço de limpeza e conservação predial', 'MÊS', 12, 38500, $semad, 1, '3.3.90.37.02'],
            ['S', 3, '27502', 'Licença de sistema de gestão de contratações (SaaS)', 'MÊS', 12, 2290, $semad, 2, '3.3.90.40.07'],
            ['M', 10, '445566', 'Luvas de procedimento não cirúrgico, caixa com 100', 'CX', 1800, 32.4, $semus, 1, '3.3.90.30.36'],
            ['S', 9, '1520', 'Manutenção predial preventiva das escolas', 'SERV', 1, 145000, $semed, 2, '3.3.90.39.16'],
        ];
        foreach ($itens26 as $i => [$ms, $cat, $cod, $desc, $un, $qtd, $vu, $uni, $prio, $nat]) {
            $itens26[$i] = ItemPca::create(['orgao_id' => $pm->id, 'plano_contratacao_id' => $pca26->id, 'unidade_id' => $uni->id, 'numero' => $i + 1, 'material_servico' => $ms, 'categoria' => $cat, 'codigo_catalogo' => $cod, 'descricao' => $desc, 'unidade_fornecimento' => $un, 'quantidade' => $qtd, 'valor_unitario_estimado' => $vu, 'data_desejada' => '2026-0'.(($i % 6) + 2).'-15', 'prioridade' => $prio, 'natureza_despesa' => $nat]);
        }
        $pca27 = PlanoContratacao::create(['orgao_id' => $pm->id, 'ano' => 2027, 'situacao' => 'rascunho']);
        ItemPca::create(['orgao_id' => $pm->id, 'plano_contratacao_id' => $pca27->id, 'unidade_id' => $semed->id, 'numero' => 1, 'material_servico' => 'M', 'categoria' => 2, 'codigo_catalogo' => '150627', 'descricao' => 'Kit de material escolar para o ensino fundamental', 'unidade_fornecimento' => 'KIT', 'quantidade' => 3400, 'valor_unitario_estimado' => 51.2, 'data_desejada' => '2027-01-20', 'prioridade' => 1, 'natureza_despesa' => '3.3.90.30.22']);

        // Demandas
        $dKits = Demanda::create(['orgao_id' => $pm->id, 'unidade_id' => $semed->id, 'item_pca_id' => $itens26[1]->id, 'solicitante_id' => $u['requisitante']->id, 'numero' => '0001', 'ano' => 2026, 'objeto' => 'Kits de material escolar para 3.200 alunos do ensino fundamental', 'justificativa' => 'Atender os alunos matriculados na rede municipal no segundo semestre, conforme censo escolar de 2026.', 'quantidade' => 3200, 'valor_estimado' => 156480, 'prioridade' => 1, 'situacao' => 'atendida']);
        Demanda::create(['orgao_id' => $pm->id, 'unidade_id' => $semus->id, 'item_pca_id' => $itens26[4]->id, 'solicitante_id' => $u['requisitante']->id, 'numero' => '0002', 'ano' => 2026, 'objeto' => 'Luvas de procedimento para as unidades básicas de saúde', 'justificativa' => 'Reposição do estoque das 6 unidades básicas de saúde para o último trimestre, com base no consumo médio mensal.', 'quantidade' => 1800, 'valor_estimado' => 58320, 'prioridade' => 1, 'situacao' => 'enviada']);
        Demanda::create(['orgao_id' => $pm->id, 'unidade_id' => $semed->id, 'solicitante_id' => $u['requisitante']->id, 'numero' => '0003', 'ano' => 2026, 'objeto' => 'Bebedouros industriais para 4 escolas', 'justificativa' => 'Os bebedouros atuais estão sem conserto e a demanda não estava prevista no PCA por quebra inesperada.', 'valor_estimado' => 22000, 'prioridade' => 2, 'situacao' => 'enviada']);

        // Processo 1: pregão eletrônico na fase preparatória, instrução parcial
        $pregao = $this->processo($pm, $semed, [
            'numero' => '012', 'ano' => 2026, 'numero_compra' => 'PE 005/2026', 'demanda_id' => $dKits->id, 'item_pca_id' => $itens26[1]->id, 'responsavel_id' => $u['agente-contratacao']->id,
            'objeto' => 'Aquisição de kits de material escolar para os alunos do ensino fundamental da rede municipal.',
            'modalidade' => 6, 'amparo_legal' => 1, 'criterio_julgamento' => 1, 'modo_disputa' => 1, 'categoria' => 2, 'srp' => true, 'natureza_despesa' => '3.3.90.30.22',
            'abertura_propostas_em' => '2026-11-20 08:00:00', 'encerramento_propostas_em' => '2026-12-04 09:00:00',
        ], 'planejamento');
        $item = $pregao->itens()->create(['orgao_id' => $pm->id, 'item_pca_id' => $itens26[1]->id, 'numero' => 1, 'material_servico' => 'M', 'codigo_catalogo' => '150627', 'descricao' => 'Kit de material escolar para o ensino fundamental', 'unidade_medida' => 'KIT', 'quantidade' => 3200, 'tipo_beneficio' => 3]);
        foreach ([['painel_oficial', 'Painel de Preços', null, 47.8, '-20 days'], ['contratacoes_similares', 'Prefeitura de Município Vizinho (fictícia), PE 31/2026', null, 49.9, '-4 months'], ['fornecedor', 'Distribuidora Norte Escolar Ltda (fictícia)', '99.000.444/0001-94', 51.3, '-10 days'], ['fornecedor', 'Papelaria Horizonte Ltda (fictícia)', '99.000.333/0001-88', 48.2, '-8 days'], ['midia_especializada', 'Loja on-line de material escolar', null, 96.0, '-3 days']] as [$fonte, $ref, $doc, $valor, $quando]) {
            $item->cotacoes()->create(['orgao_id' => $pm->id, 'fonte' => $fonte, 'referencia' => $ref, 'documento_fonte' => $doc, 'valor_unitario' => $valor, 'data_cotacao' => today()->modify($quando)]);
        }
        $item->forceFill(['valor_unitario_estimado' => 49.05, 'valor_total_estimado' => 156960])->save();
        $pregao->recalcularValor();
        $this->documento($pregao, 'dfd', ['necessidade' => 'Garantir material escolar aos alunos da rede municipal.', 'quantidade_justificada' => '3.200 alunos matriculados (censo escolar 2026), um kit por aluno.', 'previsao_pca' => 'Item 2 do PCA 2026.', 'responsavel' => 'Ana Beatriz Martins, SEMED'], $u['planejamento']);
        $this->documento($pregao, 'etp', ['i_necessidade' => 'Alunos sem material escolar no início do semestre.', 'iv_quantidades' => '3.200 kits, um por aluno matriculado.', 'vi_estimativa_valor' => 'R$ 156.960,00, pela mediana de 4 cotações válidas.', 'viii_parcelamento' => 'Lote único: o kit é padronizado e a divisão encareceria a logística.', 'xiii_viabilidade' => 'A contratação é viável e adequada.'], $u['planejamento']);

        // Processo 2: dispensa por valor já homologada (conta para o limite do art. 75, II)
        $dispensa = $this->processo($pm, $semad, [
            'numero' => '009', 'ano' => 2026, 'numero_compra' => 'DL 003/2026', 'item_pca_id' => $itens26[0]->id, 'responsavel_id' => $u['agente-contratacao']->id,
            'objeto' => 'Aquisição de papel A4 para as secretarias municipais.',
            'modalidade' => 8, 'amparo_legal' => 19, 'criterio_julgamento' => 1, 'modo_disputa' => 4, 'categoria' => 2, 'com_disputa' => true, 'natureza_despesa' => '3.3.90.30.16',
        ], 'homologado');
        $itemD = $dispensa->itens()->create(['orgao_id' => $pm->id, 'item_pca_id' => $itens26[0]->id, 'numero' => 1, 'material_servico' => 'M', 'codigo_catalogo' => '461543', 'descricao' => 'Papel A4 75 g/m², resma com 500 folhas', 'unidade_medida' => 'RESMA', 'quantidade' => 1500, 'tipo_beneficio' => 1, 'situacao' => 'homologado', 'fornecedor_vencedor_id' => $f[0]->id, 'valor_unitario_homologado' => 26.4]);
        $itemD->forceFill(['valor_unitario_estimado' => 27.5, 'valor_total_estimado' => 41250])->save();
        $dispensa->forceFill(['valor_estimado' => 41250, 'valor_homologado' => 39600, 'numero_controle_pncp' => '2026/41'])->save();
        foreach (['analise_juridica', 'aguardando_autorizacao', 'publicado', 'homologado'] as $i => $para) {
            EventoProcesso::create(['orgao_id' => $pm->id, 'processo_id' => $dispensa->id, 'user_id' => $i === 0 ? $u['agente-contratacao']->id : ($i === 1 ? $u['juridico']->id : $u['autoridade']->id), 'de' => $i === 0 ? 'planejamento' : null, 'para' => $para]);
        }

        // Processo 3: pregão de limpeza já contratado, com contrato vencendo
        $limpeza = $this->processo($pm, $semad, [
            'numero' => '031', 'ano' => 2025, 'numero_compra' => 'PE 014/2025', 'justificativa_fora_pca' => 'Contrato de limpeza anterior à adoção do PCA no sistema; previsão registrada no plano em papel de 2025.', 'responsavel_id' => $u['agente-contratacao']->id,
            'objeto' => 'Serviço contínuo de limpeza e conservação dos prédios da administração.',
            'modalidade' => 6, 'amparo_legal' => 1, 'criterio_julgamento' => 1, 'modo_disputa' => 1, 'categoria' => 6, 'natureza_despesa' => '3.3.90.37.02',
        ], 'contratado');
        $limpeza->forceFill(['valor_estimado' => 470000, 'valor_homologado' => 444000, 'numero_controle_pncp' => '2025/118'])->save();

        $contrato = Contrato::create([
            'orgao_id' => $pm->id, 'processo_id' => $limpeza->id, 'fornecedor_id' => $f[3]->id, 'gestor_id' => $u['gestor-contrato']->id, 'fiscal_id' => $u['gestor-contrato2']->id,
            'numero' => '021', 'ano' => 2025, 'tipo' => 1, 'objeto' => $limpeza->objeto, 'categoria' => 6, 'servico_continuo' => true,
            'valor_inicial' => 444000, 'valor_global' => 444000, 'garantia_percentual' => 5,
            'assinatura' => today()->subYear()->addDays(40), 'vigencia_inicio' => today()->subYear()->addDays(45), 'vigencia_fim' => today()->addDays(45), 'situacao' => 'vigente',
        ]);
        $contrato->forceFill(['numero_controle_pncp' => '2025/77'])->save();
        $contrato->termos()->create(['orgao_id' => $pm->id, 'tipo' => 'aditivo_valor', 'numero' => '1º TA', 'assinatura' => today()->subMonths(3), 'valor_acrescimo' => 66600, 'justificativa' => 'Inclusão da limpeza do novo prédio da SEMAD (15% do valor inicial, art. 125).']);
        $contrato->forceFill(['valor_global' => 510600])->save();

        Contrato::create([
            'orgao_id' => $pm->id, 'fornecedor_id' => $f[2]->id, 'gestor_id' => $u['gestor-contrato']->id, 'fiscal_id' => $u['gestor-contrato2']->id,
            'numero' => '004', 'ano' => 2026, 'tipo' => 1, 'objeto' => 'Licença de sistema de gestão de contratações públicas em nuvem, com implantação e suporte.', 'categoria' => 3, 'servico_continuo' => true,
            'valor_inicial' => 27480, 'valor_global' => 27480,
            'assinatura' => '2026-02-01', 'vigencia_inicio' => '2026-02-01', 'vigencia_fim' => '2027-01-31', 'situacao' => 'vigente',
        ]);

        // LGPD
        foreach ([
            ['Cadastro de usuários do sistema', 'Controlar quem acessa o sistema e o que cada pessoa fez.', 'art7_II', 'Nome, e-mail, CPF, perfil, IP e registros de acesso', 'Servidores e colaboradores', null, 'Enquanto ativo e 5 anos após a saída', 'Senha com hash, 2FA para administradores, trilha de auditoria imutável'],
            ['Cadastro de fornecedores', 'Conduzir contratações e publicar os atos no PNCP.', 'art7_II', 'Razão social, CNPJ, CPF de pessoa física, contato', 'Fornecedores e representantes', 'PNCP (publicidade obrigatória, art. 174 da Lei 14.133)', '10 anos após o fim do contrato', 'CPF cifrado e mascarado na tela, acesso por perfil'],
            ['Pedidos do titular', 'Atender os direitos do art. 18 da LGPD.', 'art7_II', 'Nome, e-mail, CPF e pedido', 'Cidadãos', null, '5 anos', 'CPF cifrado, acesso só do encarregado'],
        ] as [$nome, $fin, $base, $dados, $tit, $comp, $ret, $med]) {
            OperacaoTratamento::create(['orgao_id' => $pm->id, 'nome' => $nome, 'finalidade' => $fin, 'base_legal' => $base, 'dados_tratados' => $dados, 'titulares' => $tit, 'compartilhamento' => $comp, 'retencao' => $ret, 'medidas_seguranca' => $med]);
        }
        SolicitacaoTitular::create(['orgao_id' => $pm->id, 'nome' => 'Maria das Dores Exemplo', 'email' => 'maria@exemplo.com.br', 'tipo' => 'acesso', 'descricao' => 'Quero saber quais dados meus constam no cadastro de fornecedores.']);
    }

    private function unidade(Orgao $orgao, string $sigla, string $nome, string $tipo, ?Unidade $pai, ?User $chefe, ?string $codigoPncp): Unidade
    {
        return Unidade::withoutGlobalScopes()->firstOrCreate(
            ['orgao_id' => $orgao->id, 'sigla' => $sigla],
            ['nome' => $nome, 'tipo' => $tipo, 'parent_id' => $pai?->id, 'chefe_user_id' => $chefe?->id, 'codigo_unidade_pncp' => $codigoPncp, 'email' => strtolower($sigla).'@exemplo.gov.br'],
        );
    }

    private function processo(Orgao $orgao, Unidade $unidade, array $dados, string $situacao): Processo
    {
        $p = Processo::create($dados + ['orgao_id' => $orgao->id, 'unidade_id' => $unidade->id]);
        $p->forceFill(['situacao' => $situacao])->save();

        return $p;
    }

    private function documento(Processo $p, string $tipo, array $conteudo, User $autor): void
    {
        DocumentoProcesso::create(['orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'tipo' => $tipo, 'versao' => 1, 'conteudo' => $conteudo, 'situacao' => 'rascunho', 'autor_id' => $autor->id])->concluir();
    }
}
