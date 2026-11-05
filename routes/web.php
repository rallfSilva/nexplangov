<?php

use App\Http\Controllers\AnotacaoProcessoController;
use App\Http\Controllers\AtaController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\CapacitacaoController;
use App\Http\Controllers\CaronaController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CicloContratoController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\DemandaController;
use App\Http\Controllers\DevolucaoDadosController;
use App\Http\Controllers\DispensaController;
use App\Http\Controllers\DocumentoProcessoController;
use App\Http\Controllers\ExportacaoController;
use App\Http\Controllers\FluxoController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\GovernancaController;
use App\Http\Controllers\IaController;
use App\Http\Controllers\ImpugnacaoController;
use App\Http\Controllers\IntegracaoController;
use App\Http\Controllers\IrpController;
use App\Http\Controllers\ItemProcessoController;
use App\Http\Controllers\LgpdController;
use App\Http\Controllers\LicitacaoController;
use App\Http\Controllers\MasterController;
use App\Http\Controllers\ModeloController;
use App\Http\Controllers\OcorrenciaContratoController;
use App\Http\Controllers\OrdemController;
use App\Http\Controllers\OrgaoController;
use App\Http\Controllers\PagamentoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PcaController;
use App\Http\Controllers\PcaImportacaoController;
use App\Http\Controllers\PncpController;
use App\Http\Controllers\PrecosComprasGovController;
use App\Http\Controllers\ProcessoController;
use App\Http\Controllers\RequisicaoController;
use App\Http\Controllers\SancaoController;
use App\Http\Controllers\SegurancaController;
use App\Http\Controllers\UnidadeController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\ValidacaoAssinaturaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/painel');

// Páginas públicas da LGPD: aviso de privacidade e canal do titular (art. 18 e art. 41).
Route::view('/privacidade', 'lgpd.privacidade')->name('privacidade');
Route::get('/lgpd/solicitar', [LgpdController::class, 'formularioPublico'])->name('lgpd.publico');
Route::post('/lgpd/solicitar', [LgpdController::class, 'registrarPublico'])->middleware('throttle:5,10')->name('lgpd.publico.registrar');

// Validação pública de assinatura eletrônica (código ou QR Code da folha de assinaturas).
Route::post('/validar', [ValidacaoAssinaturaController::class, 'buscar'])->middleware('throttle:20,1')->name('assinaturas.buscar');
Route::get('/validar/{codigo?}', [ValidacaoAssinaturaController::class, 'mostrar'])->middleware('throttle:30,1')->where('codigo', '[A-Za-z0-9-]+')->name('assinaturas.validar');

// Página pública do edital: respostas divulgadas e pedido de impugnação ou esclarecimento (art. 164).
Route::get('/editais/{processo}', [ImpugnacaoController::class, 'edital'])->middleware('throttle:60,1')->name('edital.publico');
Route::get('/editais/{processo}/ata-sessao', [ImpugnacaoController::class, 'ataPublica'])->middleware('throttle:30,1')->name('edital.ata');
Route::post('/editais/{processo}/pedidos', [ImpugnacaoController::class, 'pedidoPublico'])->middleware('throttle:5,10')->name('edital.pedido');

// Carona (art. 86, § 2º): atas vigentes, pedido de adesão de órgão de fora com protocolo, aceite do fornecedor
// por link assinado e verificação pública da autorização pelo QR Code.
Route::get('/carona', [CaronaController::class, 'index'])->middleware('throttle:60,1')->name('carona.index');
Route::get('/carona/atas/{ata}', [CaronaController::class, 'ata'])->middleware('throttle:60,1')->name('carona.ata');
Route::post('/carona/atas/{ata}/pedidos', [CaronaController::class, 'pedir'])->middleware('throttle:5,10')->name('carona.pedir');
Route::get('/carona/consulta', [CaronaController::class, 'consulta'])->middleware('throttle:30,1')->name('carona.consulta');
Route::post('/carona/consulta', [CaronaController::class, 'consultar'])->middleware('throttle:10,1')->name('carona.consultar');
Route::get('/carona/aceite/{adesao}', [CaronaController::class, 'aceite'])->middleware(['signed', 'throttle:30,1'])->name('carona.aceite');
Route::post('/carona/aceite/{adesao}', [CaronaController::class, 'responderAceite'])->middleware(['signed', 'throttle:5,10'])->name('carona.aceite.responder');
Route::get('/carona/verificar/{codigo}', [CaronaController::class, 'verificar'])->middleware('throttle:30,1')->where('codigo', '[A-Za-z0-9-]+')->name('carona.verificar');
Route::get('/carona/verificar/{codigo}/pdf', [CaronaController::class, 'autorizacao'])->middleware('throttle:30,1')->where('codigo', '[A-Za-z0-9-]+')->name('carona.autorizacao');

// Ordem cronológica de pagamentos e justificativas, divulgada todo mês (art. 141, § 3º).
Route::get('/transparencia/{orgao}/pagamentos', [PagamentoController::class, 'publico'])->middleware('throttle:60,1')->whereNumber('orgao')->name('pagamentos.publico');

Route::middleware(['auth', 'ativo'])->group(function () {
    // Fica fora da exigência de 2FA: é aqui que o administrador ativa a verificação.
    Route::get('/seguranca', SegurancaController::class)->name('seguranca');

    Route::middleware('dois-fatores')->group(function () {
        Route::get('/painel', PainelController::class)->middleware('can:painel.ver')->name('painel');

        // Estrutura
        Route::resource('orgaos', OrgaoController::class)->except(['show', 'destroy'])->parameters(['orgaos' => 'orgao'])->middleware('can:orgaos.gerenciar');
        Route::get('/unidades', [UnidadeController::class, 'index'])->middleware('can:unidades.ver')->name('unidades.index');
        Route::resource('unidades', UnidadeController::class)->except(['index', 'show'])->parameters(['unidades' => 'unidade'])->middleware('can:unidades.gerenciar');
        Route::get('/usuarios', [UsuarioController::class, 'index'])->middleware('can:usuarios.ver')->name('usuarios.index');
        Route::middleware('can:usuarios.gerenciar')->group(function () {
            Route::resource('usuarios', UsuarioController::class)->except(['index', 'show', 'destroy'])->parameters(['usuarios' => 'usuario']);
            Route::patch('/usuarios/{usuario}/ativo', [UsuarioController::class, 'alternarAtivo'])->name('usuarios.ativo');
            Route::post('/usuarios/{usuario}/link-senha', [UsuarioController::class, 'enviarLinkSenha'])->middleware('throttle:6,1')->name('usuarios.link-senha');
        });
        Route::get('/auditoria', [AuditoriaController::class, 'index'])->middleware('can:auditoria.ver')->name('auditoria.index');
        Route::middleware('can:orgaos.gerenciar')->group(function () {
            Route::get('/configuracoes', [ConfiguracaoController::class, 'index'])->name('configuracoes.index');
            Route::post('/configuracoes/valores', [ConfiguracaoController::class, 'salvarValor'])->name('configuracoes.valores');
        });

        // Catálogo CATMAT/CATSER e painel master
        Route::middleware('can:catalogo.ver')->group(function () {
            Route::get('/catalogo', [CatalogoController::class, 'index'])->name('catalogo.index');
            Route::get('/catalogo/buscar', [CatalogoController::class, 'buscar'])->middleware('throttle:60,1')->name('catalogo.buscar');
        });
        Route::get('/capacitacao', [CapacitacaoController::class, 'index'])->middleware('can:capacitacao.ver')->name('capacitacao.index');
        Route::post('/capacitacao', [CapacitacaoController::class, 'store'])->middleware('can:capacitacao.ver')->name('capacitacao.store');
        Route::get('/governanca', GovernancaController::class)->middleware('can:governanca.ver')->name('governanca');
        Route::middleware('can:exportacoes.gerar')->group(function () {
            Route::get('/integracoes', [IntegracaoController::class, 'index'])->name('integracoes.index');
            Route::post('/integracoes/tce', [IntegracaoController::class, 'tce'])->middleware('throttle:6,1')->name('integracoes.tce');
        });
        Route::get('/processos/{processo}/dossie', [IntegracaoController::class, 'dossie'])->middleware(['can:processos.ver', 'throttle:10,1'])->name('processos.dossie');
        Route::patch('/processos/{processo}/sei', [IntegracaoController::class, 'numeroSei'])->middleware('can:processos.gerenciar')->name('processos.sei');
        Route::middleware('can:devolucao.gerar')->group(function () {
            Route::get('/devolucao-de-dados', [DevolucaoDadosController::class, 'index'])->name('devolucao.index');
            Route::post('/devolucao-de-dados', [DevolucaoDadosController::class, 'store'])->middleware('throttle:5,1')->name('devolucao.store');
            Route::get('/devolucao-de-dados/{pacote}/baixar', [DevolucaoDadosController::class, 'baixar'])->middleware('throttle:10,1')->name('devolucao.baixar');
        });
        Route::get('/master', MasterController::class)->middleware('can:master.ver')->name('master');

        // PCA (art. 12, VII)
        Route::get('/pca', [PcaController::class, 'index'])->middleware('can:pca.ver')->name('pca.index');
        Route::get('/pca/{plano}', [PcaController::class, 'show'])->middleware('can:pca.ver')->name('pca.show');
        Route::middleware('can:pca.gerenciar')->group(function () {
            Route::post('/pca', [PcaController::class, 'store'])->name('pca.store');
            Route::post('/pca/{plano}/itens', [PcaController::class, 'salvarItem'])->name('pca.itens.store');
            Route::put('/pca/{plano}/itens/{item}', [PcaController::class, 'salvarItem'])->name('pca.itens.update');
            Route::delete('/pca/{plano}/itens/{item}', [PcaController::class, 'excluirItem'])->name('pca.itens.destroy');
            Route::post('/pca/{plano}/enviar-aprovacao', [PcaController::class, 'enviarAprovacao'])->name('pca.enviar');
            Route::get('/pca/{plano}/importacoes/modelo', [PcaImportacaoController::class, 'modelo'])->name('pca.importacoes.modelo');
            Route::post('/pca/{plano}/importacoes', [PcaImportacaoController::class, 'store'])->middleware('throttle:20,1')->name('pca.importacoes.store');
            Route::get('/pca/{plano}/importacoes/{importacao}', [PcaImportacaoController::class, 'show'])->name('pca.importacoes.show');
            Route::post('/pca/{plano}/importacoes/{importacao}/confirmar', [PcaImportacaoController::class, 'confirmar'])->name('pca.importacoes.confirmar');
            Route::post('/pca/{plano}/importacoes/{importacao}/cancelar', [PcaImportacaoController::class, 'cancelar'])->name('pca.importacoes.cancelar');
        });
        Route::post('/pca/{plano}/aprovar', [PcaController::class, 'aprovar'])->middleware('can:pca.aprovar')->name('pca.aprovar');
        Route::get('/pca/{plano}/exportar/{formato}', [ExportacaoController::class, 'pca'])->middleware(['can:pca.ver', 'throttle:20,1'])->whereIn('formato', ['xlsx', 'docx', 'csv'])->name('pca.exportar');
        Route::get('/pca/{plano}/versoes', [PcaController::class, 'versoes'])->middleware('can:pca.ver')->name('pca.versoes');
        Route::post('/pca/{plano}/revisao', [PcaController::class, 'abrirRevisao'])->middleware('can:pca.gerenciar')->name('pca.revisao.abrir');
        Route::post('/pca/{plano}/revisao/descartar', [PcaController::class, 'descartarRevisao'])->middleware('can:pca.gerenciar')->name('pca.revisao.descartar');

        // Demandas (DFD)
        Route::get('/demandas', [DemandaController::class, 'index'])->middleware('can:demandas.ver')->name('demandas.index');
        Route::middleware('can:demandas.criar')->group(function () {
            Route::get('/demandas/nova', [DemandaController::class, 'create'])->name('demandas.create');
            Route::post('/demandas', [DemandaController::class, 'store'])->name('demandas.store');
        });
        Route::patch('/demandas/{demanda}/analisar', [DemandaController::class, 'analisar'])->middleware('can:pca.gerenciar')->name('demandas.analisar');
        Route::post('/demandas/prazos', [DemandaController::class, 'definirPrazo'])->middleware('can:pca.gerenciar')->name('demandas.prazos');

        // Processos
        Route::get('/processos', [ProcessoController::class, 'index'])->middleware('can:processos.ver')->name('processos.index');
        Route::get('/processos/exportar.xlsx', [ExportacaoController::class, 'processos'])->middleware(['can:processos.ver', 'throttle:10,1'])->name('processos.exportar');
        Route::get('/processos/{processo}/documentos/{tipo}/docx', [ExportacaoController::class, 'peca'])->middleware(['can:processos.ver', 'throttle:20,1'])->name('documentos.docx');
        Route::middleware('can:processos.gerenciar')->group(function () {
            Route::get('/processos/novo', [ProcessoController::class, 'create'])->name('processos.create');
            Route::post('/processos', [ProcessoController::class, 'store'])->name('processos.store');
            Route::get('/processos/{processo}/editar', [ProcessoController::class, 'edit'])->name('processos.edit');
            Route::put('/processos/{processo}', [ProcessoController::class, 'update'])->name('processos.update');
            Route::post('/processos/{processo}/duplicar', [ProcessoController::class, 'duplicar'])->name('processos.duplicar');
            Route::post('/processos/{processo}/itens', [ItemProcessoController::class, 'store'])->name('processos.itens.store');
            Route::put('/processos/{processo}/itens/{item}', [ItemProcessoController::class, 'update'])->name('processos.itens.update');
            Route::delete('/processos/{processo}/itens/{item}', [ItemProcessoController::class, 'destroy'])->name('processos.itens.destroy');
            Route::get('/processos/{processo}/itens/{item}/compras-gov', [PrecosComprasGovController::class, 'mostrar'])->middleware('throttle:20,1')->name('processos.comprasgov');
            Route::post('/processos/{processo}/itens/{item}/compras-gov', [PrecosComprasGovController::class, 'importar'])->name('processos.comprasgov.importar');
            Route::patch('/processos/{processo}/itens/{item}/metodo', [ItemProcessoController::class, 'metodo'])->name('processos.itens.metodo');
            Route::patch('/processos/{processo}/itens/{item}/resultado', [ItemProcessoController::class, 'resultado'])->name('processos.itens.resultado');
            Route::post('/processos/{processo}/itens/{item}/cotacoes', [ItemProcessoController::class, 'adicionarCotacao'])->name('processos.cotacoes.store');
            Route::put('/processos/{processo}/cotacoes/{cotacao}', [ItemProcessoController::class, 'editarCotacao'])->name('processos.cotacoes.update');
            Route::patch('/processos/{processo}/cotacoes/{cotacao}', [ItemProcessoController::class, 'desconsiderarCotacao'])->name('processos.cotacoes.desconsiderar');
            Route::delete('/processos/{processo}/cotacoes/{cotacao}', [ItemProcessoController::class, 'excluirCotacao'])->name('processos.cotacoes.destroy');
        });
        Route::get('/processos/{processo}', [ProcessoController::class, 'show'])->middleware('can:processos.ver')->name('processos.show');
        Route::post('/processos/{processo}/anotacoes', [AnotacaoProcessoController::class, 'store'])->middleware('can:processos.ver')->name('processos.anotacoes.store');
        Route::put('/processos/{processo}/anotacoes/{anotacao}', [AnotacaoProcessoController::class, 'update'])->middleware('can:processos.ver')->name('processos.anotacoes.update');
        Route::delete('/processos/{processo}/anotacoes/{anotacao}', [AnotacaoProcessoController::class, 'destroy'])->middleware('can:processos.ver')->name('processos.anotacoes.destroy');
        Route::post('/processos/{processo}/tramitar', [ProcessoController::class, 'tramitar'])->middleware('can:processos.ver')->name('processos.tramitar');
        Route::get('/processos/{processo}/documentos/{tipo}', [DocumentoProcessoController::class, 'edit'])->middleware('can:processos.ver')->name('documentos.edit');
        Route::post('/processos/{processo}/documentos/{tipo}', [DocumentoProcessoController::class, 'salvar'])->middleware('can:processos.ver')->name('documentos.salvar');
        Route::post('/processos/{processo}/documentos/{tipo}/decidir', [DocumentoProcessoController::class, 'decidir'])->middleware('can:processos.ver')->name('documentos.decidir');
        Route::post('/processos/{processo}/documentos/{tipo}/ia', IaController::class)->middleware(['can:ia.usar', 'throttle:12,1'])->name('documentos.ia');
        Route::post('/processos/{processo}/documentos/{tipo}/assinar', [DocumentoProcessoController::class, 'assinar'])->middleware(['can:processos.ver', 'throttle:10,1'])->name('documentos.assinar');
        Route::get('/processos/{processo}/documentos/{tipo}/folha', [DocumentoProcessoController::class, 'folha'])->middleware('can:processos.ver')->name('documentos.folha');
        Route::get('/processos/{processo}/arquivos/{documento}', [DocumentoProcessoController::class, 'arquivo'])->middleware('can:processos.ver')->name('documentos.arquivo');
        Route::post('/processos/{processo}/fluxo', [FluxoController::class, 'aplicar'])->middleware('can:processos.gerenciar')->name('processos.fluxo');

        Route::middleware('can:modelos.gerenciar')->group(function () {
            Route::get('/modelos', [ModeloController::class, 'index'])->name('modelos.index');
            Route::get('/modelos/novo', [ModeloController::class, 'create'])->name('modelos.create');
            Route::post('/modelos', [ModeloController::class, 'store'])->name('modelos.store');
            Route::get('/modelos/{modelo}', [ModeloController::class, 'edit'])->name('modelos.edit');
            Route::put('/modelos/{modelo}', [ModeloController::class, 'update'])->name('modelos.update');
            Route::post('/modelos/{modelo}/duplicar', [ModeloController::class, 'duplicar'])->name('modelos.duplicar');
            Route::post('/modelos/{modelo}/alternar', [ModeloController::class, 'alternar'])->name('modelos.alternar');
            Route::post('/modelos/{modelo}/restaurar/{versao}', [ModeloController::class, 'restaurar'])->whereNumber('versao')->name('modelos.restaurar');
        });
        Route::middleware('can:fluxos.gerenciar')->group(function () {
            Route::get('/fluxos', [FluxoController::class, 'index'])->name('fluxos.index');
            Route::get('/fluxos/novo', [FluxoController::class, 'create'])->name('fluxos.create');
            Route::post('/fluxos', [FluxoController::class, 'store'])->name('fluxos.store');
            Route::get('/fluxos/{fluxo}', [FluxoController::class, 'edit'])->name('fluxos.edit');
            Route::put('/fluxos/{fluxo}', [FluxoController::class, 'update'])->name('fluxos.update');
            Route::delete('/fluxos/{fluxo}', [FluxoController::class, 'destroy'])->name('fluxos.destroy');
        });
        // NexLicita: sessão de julgamento, impugnações e IRP
        Route::get('/processos/{processo}/licitacao', [LicitacaoController::class, 'show'])->middleware('can:processos.ver')->name('licitacao.show');
        Route::middleware('can:processos.ver')->group(function () {
            Route::get('/processos/{processo}/sessao/ata', [LicitacaoController::class, 'ata'])->name('licitacao.ata');
            Route::get('/processos/{processo}/sessao/gravacao', [LicitacaoController::class, 'baixarGravacao'])->name('licitacao.gravacao.baixar');
        });
        Route::post('/processos/{processo}/recursos/{recurso}/decidir', [LicitacaoController::class, 'decidirRecurso'])->middleware('can:processos.autorizar')->name('recursos.decidir');
        Route::get('/processos/{processo}/impugnacoes', [ImpugnacaoController::class, 'index'])->middleware('can:processos.ver')->name('impugnacoes.index');
        Route::middleware('can:licitacao.conduzir')->group(function () {
            Route::post('/processos/{processo}/impugnacoes', [ImpugnacaoController::class, 'store'])->name('impugnacoes.store');
            Route::post('/processos/{processo}/impugnacoes/{impugnacao}/responder', [ImpugnacaoController::class, 'responder'])->name('impugnacoes.responder');
            Route::post('/processos/{processo}/itens/{item}/propostas', [LicitacaoController::class, 'proposta'])->name('licitacao.proposta');
            Route::post('/processos/{processo}/propostas/{proposta}/lances', [LicitacaoController::class, 'lance'])->name('licitacao.lance');
            Route::post('/processos/{processo}/propostas/{proposta}/desclassificar', [LicitacaoController::class, 'desclassificar'])->name('licitacao.desclassificar');
            Route::post('/processos/{processo}/propostas/{proposta}/habilitar', [LicitacaoController::class, 'habilitar'])->name('licitacao.habilitar');
            Route::post('/processos/{processo}/propostas/{proposta}/inabilitar', [LicitacaoController::class, 'inabilitar'])->name('licitacao.inabilitar');
            Route::post('/processos/{processo}/propostas/{proposta}/desempate', [LicitacaoController::class, 'desempate'])->name('licitacao.desempate');
            Route::post('/processos/{processo}/itens/{item}/adjudicar', [LicitacaoController::class, 'adjudicar'])->name('licitacao.adjudicar');
            Route::post('/processos/{processo}/itens/{item}/revogar-adjudicacao', [LicitacaoController::class, 'revogarAdjudicacao'])->name('licitacao.revogar');
            Route::post('/processos/{processo}/sessao/gravacao', [LicitacaoController::class, 'gravacao'])->name('licitacao.gravacao');
            Route::post('/processos/{processo}/sessao/encerrar', [LicitacaoController::class, 'encerrar'])->name('licitacao.encerrar');
            Route::post('/processos/{processo}/sessao/reabrir', [LicitacaoController::class, 'reabrir'])->name('licitacao.reabrir');
            Route::post('/processos/{processo}/recursos', [LicitacaoController::class, 'intencao'])->name('recursos.intencao');
            Route::post('/processos/{processo}/recursos/{recurso}/razoes', [LicitacaoController::class, 'razoes'])->name('recursos.razoes');
            Route::post('/processos/{processo}/recursos/{recurso}/contrarrazoes', [LicitacaoController::class, 'contrarrazoes'])->name('recursos.contrarrazoes');
            Route::post('/processos/{processo}/recursos/{recurso}/reconsiderar', [LicitacaoController::class, 'reconsiderar'])->name('recursos.reconsiderar');
        });

        Route::middleware('can:irp.ver')->group(function () {
            Route::get('/irp', [IrpController::class, 'index'])->name('irp.index');
            Route::get('/irp/{irp}', [IrpController::class, 'show'])->name('irp.show');
        });
        Route::post('/irp/{irp}/manifestacoes', [IrpController::class, 'manifestar'])->middleware('can:irp.manifestar')->name('irp.manifestar');
        Route::middleware('can:irp.gerenciar')->group(function () {
            Route::post('/irp', [IrpController::class, 'store'])->name('irp.store');
            Route::post('/irp/{irp}/manifestacoes/{manifestacao}/decidir', [IrpController::class, 'decidir'])->name('irp.decidir');
            Route::post('/irp/{irp}/encerrar', [IrpController::class, 'encerrar'])->name('irp.encerrar');
            Route::post('/irp/{irp}/consolidar', [IrpController::class, 'consolidar'])->name('irp.consolidar');
        });
        // Atas de registro de preços, reequilíbrio e extinção de contrato
        Route::middleware('can:contratos.ver')->group(function () {
            Route::get('/atas', [AtaController::class, 'index'])->name('atas.index');
            Route::get('/atas/{ata}', [AtaController::class, 'show'])->name('atas.show');
            Route::get('/atas/{ata}/adesoes/{adesao}/arquivos/{tipo}', [AtaController::class, 'arquivoAdesao'])->whereIn('tipo', ['oficio', 'aceite', 'autorizacao'])->name('atas.adesao.arquivo');
        });
        Route::middleware('can:contratos.gerenciar')->group(function () {
            Route::post('/atas', [AtaController::class, 'gerar'])->name('atas.gerar');
            Route::post('/atas/{ata}/itens/{item}/consumos', [AtaController::class, 'consumo'])->name('atas.consumo');
            Route::post('/atas/{ata}/itens/{item}/adesoes', [AtaController::class, 'solicitarAdesao'])->name('atas.adesao');
            Route::post('/atas/{ata}/adesoes/{adesao}/aceite', [AtaController::class, 'aceiteAdesao'])->name('atas.adesao.aceite');
            Route::post('/atas/{ata}/adesoes/{adesao}/decidir', [AtaController::class, 'decidirAdesao'])->name('atas.adesao.decidir');
            Route::post('/atas/{ata}/prorrogar', [AtaController::class, 'prorrogar'])->name('atas.prorrogar');
            Route::post('/atas/{ata}/cancelar', [AtaController::class, 'cancelar'])->name('atas.cancelar');
            Route::post('/contratos/{contrato}/reequilibrios', [CicloContratoController::class, 'solicitarReequilibrio'])->name('reequilibrios.store');
            Route::post('/contratos/{contrato}/rescisoes', [CicloContratoController::class, 'iniciarRescisao'])->name('rescisoes.store');
            Route::post('/contratos/{contrato}/rescisoes/{rescisao}/defesa', [CicloContratoController::class, 'defesa'])->name('rescisoes.defesa');
            Route::post('/contratos/{contrato}/rescisoes/{rescisao}/cancelar', [CicloContratoController::class, 'cancelarRescisao'])->name('rescisoes.cancelar');
        });
        // Requisições, ordens de fornecimento e recebimento (art. 140)
        Route::middleware('can:requisicoes.ver')->get('/requisicoes', [RequisicaoController::class, 'index'])->name('requisicoes.index');
        Route::middleware('can:requisicoes.criar')->post('/requisicoes', [RequisicaoController::class, 'store'])->name('requisicoes.store');
        Route::middleware('can:contratos.ver')->group(function () {
            Route::get('/ordens', [OrdemController::class, 'index'])->name('ordens.index');
            Route::get('/ordens/{ordem}', [OrdemController::class, 'show'])->name('ordens.show');
        });
        Route::middleware('can:contratos.gerenciar')->group(function () {
            Route::post('/requisicoes/{requisicao}/decidir', [RequisicaoController::class, 'decidir'])->name('requisicoes.decidir');
            Route::post('/requisicoes/{requisicao}/ordem', [OrdemController::class, 'emitir'])->name('ordens.emitir');
            Route::post('/ordens/{ordem}/recebimentos', [OrdemController::class, 'receber'])->name('ordens.receber');
            Route::post('/ordens/{ordem}/cancelar', [OrdemController::class, 'cancelar'])->name('ordens.cancelar');
        });
        Route::middleware('can:processos.autorizar')->group(function () {
            Route::post('/contratos/{contrato}/reequilibrios/{reequilibrio}/decidir', [CicloContratoController::class, 'decidirReequilibrio'])->name('reequilibrios.decidir');
            Route::post('/contratos/{contrato}/rescisoes/{rescisao}/concluir', [CicloContratoController::class, 'concluirRescisao'])->name('rescisoes.concluir');
        });

        Route::get('/limite-dispensa', DispensaController::class)->middleware('can:processos.ver')->name('dispensa.simulador');

        // Fornecedores
        Route::get('/fornecedores', [FornecedorController::class, 'index'])->middleware('can:fornecedores.ver')->name('fornecedores.index');
        Route::middleware('can:fornecedores.gerenciar')->group(function () {
            Route::get('/fornecedores/novo', [FornecedorController::class, 'create'])->name('fornecedores.create');
            Route::post('/fornecedores', [FornecedorController::class, 'store'])->name('fornecedores.store');
            Route::get('/fornecedores/{fornecedor}/editar', [FornecedorController::class, 'edit'])->name('fornecedores.edit');
            Route::put('/fornecedores/{fornecedor}', [FornecedorController::class, 'update'])->name('fornecedores.update');
            Route::post('/fornecedores/{fornecedor}/sancoes', [FornecedorController::class, 'registrarSancao'])->name('fornecedores.sancoes.store');
            Route::post('/fornecedores/{fornecedor}/ceis-cnep', [FornecedorController::class, 'consultarImpedimento'])->middleware('throttle:10,1')->name('fornecedores.ceis');
        });
        Route::get('/fornecedores/{fornecedor}/ceis-cnep', [FornecedorController::class, 'impedimentos'])->middleware('can:fornecedores.ver')->name('fornecedores.ceis.index');
        Route::post('/fornecedores/{fornecedor}/ceis-cnep/{consulta}/ressalva', [FornecedorController::class, 'ressalvarImpedimento'])->middleware('can:processos.autorizar')->name('fornecedores.ceis.ressalva');

        // Medição, liquidação e pagamento na ordem cronológica (arts. 140 e 141)
        Route::get('/pagamentos', [PagamentoController::class, 'index'])->middleware('can:contratos.ver')->name('pagamentos.index');
        Route::middleware('can:contratos.gerenciar')->group(function () {
            Route::post('/contratos/{contrato}/medicoes', [PagamentoController::class, 'medir'])->name('medicoes.store');
            Route::post('/contratos/{contrato}/medicoes/{medicao}/decidir', [PagamentoController::class, 'decidirMedicao'])->name('medicoes.decidir');
        });
        Route::middleware('can:pagamentos.gerenciar')->group(function () {
            Route::post('/medicoes/{medicao}/liquidar', [PagamentoController::class, 'liquidarMedicao'])->name('medicoes.liquidar');
            Route::post('/ordens/{ordem}/liquidar', [PagamentoController::class, 'liquidarOrdem'])->name('ordens.liquidar');
            Route::post('/pagamentos/{liquidacao}/pagar', [PagamentoController::class, 'pagar'])->name('pagamentos.pagar');
            Route::post('/pagamentos/{liquidacao}/comunicacao', [PagamentoController::class, 'comunicacao'])->name('pagamentos.comunicacao');
            Route::post('/pagamentos/{liquidacao}/cancelar', [PagamentoController::class, 'cancelar'])->name('pagamentos.cancelar');
        });
        Route::post('/pagamentos/{liquidacao}/quebra', [PagamentoController::class, 'quebra'])->middleware('can:processos.autorizar')->name('pagamentos.quebra');

        // Processo sancionador (arts. 155 a 163)
        Route::middleware('can:sancoes.conduzir')->group(function () {
            Route::get('/sancoes/novo', [SancaoController::class, 'create'])->name('sancoes.create');
            Route::get('/sancoes/ceis.csv', [SancaoController::class, 'exportarCeis'])->name('sancoes.ceis.exportar');
            Route::post('/sancoes', [SancaoController::class, 'store'])->name('sancoes.store');
            Route::post('/sancoes/{sancionador}/intimar', [SancaoController::class, 'intimar'])->name('sancoes.intimar');
            Route::post('/sancoes/{sancionador}/defesa', [SancaoController::class, 'defesa'])->name('sancoes.defesa');
            Route::post('/sancoes/{sancionador}/instrucao', [SancaoController::class, 'instrucao'])->name('sancoes.instrucao');
            Route::post('/sancoes/{sancionador}/alegacoes', [SancaoController::class, 'alegacoes'])->name('sancoes.alegacoes');
            Route::post('/sancoes/{sancionador}/recurso', [SancaoController::class, 'recurso'])->name('sancoes.recurso');
            Route::post('/sancoes/{sancionador}/definitiva', [SancaoController::class, 'definitiva'])->name('sancoes.definitiva');
            Route::post('/sancoes/{sancionador}/ceis', [SancaoController::class, 'ceis'])->name('sancoes.ceis');
        });
        Route::middleware('can:fornecedores.ver')->group(function () {
            Route::get('/sancoes', [SancaoController::class, 'index'])->name('sancoes.index');
            Route::get('/sancoes/{sancionador}', [SancaoController::class, 'show'])->whereNumber('sancionador')->name('sancoes.show');
            Route::get('/sancoes/{sancionador}/arquivos/{tipo}', [SancaoController::class, 'arquivo'])->whereIn('tipo', ['intimacao', 'defesa'])->name('sancoes.arquivo');
        });
        Route::post('/sancoes/{sancionador}/parecer', [SancaoController::class, 'parecer'])->middleware('can:processos.parecer')->name('sancoes.parecer');
        Route::middleware('can:processos.autorizar')->group(function () {
            Route::post('/sancoes/{sancionador}/decidir', [SancaoController::class, 'decidir'])->name('sancoes.decidir');
            Route::post('/sancoes/{sancionador}/reconsiderar', [SancaoController::class, 'reconsiderar'])->name('sancoes.reconsiderar');
            Route::post('/sancoes/{sancionador}/decidir-recurso', [SancaoController::class, 'decidirRecurso'])->name('sancoes.recurso.decidir');
            Route::post('/sancoes/{sancionador}/reabilitar', [SancaoController::class, 'reabilitar'])->name('sancoes.reabilitar');
        });

        // Contratos
        Route::get('/contratos', [ContratoController::class, 'index'])->middleware('can:contratos.ver')->name('contratos.index');
        Route::get('/contratos/exportar.xlsx', [ExportacaoController::class, 'contratos'])->middleware(['can:contratos.ver', 'throttle:10,1'])->name('contratos.exportar');
        Route::middleware('can:contratos.gerenciar')->group(function () {
            Route::get('/contratos/novo', [ContratoController::class, 'create'])->name('contratos.create');
            Route::post('/contratos', [ContratoController::class, 'store'])->name('contratos.store');
            Route::post('/contratos/{contrato}/termos', [ContratoController::class, 'registrarTermo'])->name('contratos.termos.store');
        });
        Route::get('/contratos/{contrato}', [ContratoController::class, 'show'])->middleware('can:contratos.ver')->name('contratos.show');

        // Livro de ocorrências e designação de gestor e fiscal (art. 117)
        Route::post('/contratos/{contrato}/ocorrencias', [OcorrenciaContratoController::class, 'store'])->middleware('can:contratos.ver')->name('contratos.ocorrencias.store');
        Route::get('/contratos/{contrato}/ocorrencias.pdf', [OcorrenciaContratoController::class, 'livroPdf'])->middleware('can:contratos.ver')->name('contratos.ocorrencias.pdf');
        Route::post('/contratos/{contrato}/designacao', [OcorrenciaContratoController::class, 'designar'])->middleware('can:processos.autorizar')->name('contratos.designacao');
        Route::get('/contratos/{contrato}/designacao.pdf', [OcorrenciaContratoController::class, 'portaria'])->middleware('can:contratos.ver')->name('contratos.designacao.pdf');

        // PNCP
        Route::get('/pncp', [PncpController::class, 'index'])->middleware('can:processos.ver')->name('pncp.index');
        Route::get('/contratos/{contrato}/assinado', [PncpController::class, 'baixarContrato'])->middleware('can:contratos.ver')->name('contratos.assinado');
        Route::middleware('can:pncp.enviar')->group(function () {
            Route::post('/pncp/contratos/{contrato}', [PncpController::class, 'publicarContrato'])->name('pncp.contrato');
            Route::post('/pncp/termos/{termo}', [PncpController::class, 'publicarTermo'])->name('pncp.termo');
            Route::post('/pncp/envios/{envio}/reenviar', [PncpController::class, 'reenviar'])->name('pncp.reenviar');
            Route::post('/pncp/envios/{envio}/excluir', [PncpController::class, 'excluir'])->name('pncp.excluir');
            Route::post('/pncp/atas/{ata}', [PncpController::class, 'publicarAta'])->name('pncp.ata');
            Route::post('/pncp/pca/{plano}', [PncpController::class, 'publicarPca'])->name('pncp.pca');
            Route::post('/pncp/processos/{processo}/resultados', [PncpController::class, 'publicarResultados'])->name('pncp.resultados');
            Route::post('/pncp/contratos/{contrato}/arquivo', [PncpController::class, 'anexarContrato'])->name('pncp.contrato.arquivo');
        });

        // LGPD
        Route::middleware('can:lgpd.gerenciar')->group(function () {
            Route::get('/lgpd', [LgpdController::class, 'index'])->name('lgpd.index');
            Route::get('/lgpd/solicitacoes/{solicitacao}', [LgpdController::class, 'show'])->name('lgpd.show');
            Route::patch('/lgpd/solicitacoes/{solicitacao}', [LgpdController::class, 'responder'])->name('lgpd.responder');
            Route::post('/lgpd/tratamentos', [LgpdController::class, 'salvarTratamento'])->name('lgpd.tratamentos.store');
        });
    });
});
