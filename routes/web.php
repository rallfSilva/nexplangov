<?php

use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\ConfiguracaoController;
use App\Http\Controllers\ContratoController;
use App\Http\Controllers\DemandaController;
use App\Http\Controllers\DispensaController;
use App\Http\Controllers\DocumentoProcessoController;
use App\Http\Controllers\FornecedorController;
use App\Http\Controllers\ItemProcessoController;
use App\Http\Controllers\LgpdController;
use App\Http\Controllers\OrgaoController;
use App\Http\Controllers\PainelController;
use App\Http\Controllers\PcaController;
use App\Http\Controllers\PncpController;
use App\Http\Controllers\ProcessoController;
use App\Http\Controllers\SegurancaController;
use App\Http\Controllers\UnidadeController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/painel');

// Páginas públicas da LGPD: aviso de privacidade e canal do titular (art. 18 e art. 41).
Route::view('/privacidade', 'lgpd.privacidade')->name('privacidade');
Route::get('/lgpd/solicitar', [LgpdController::class, 'formularioPublico'])->name('lgpd.publico');
Route::post('/lgpd/solicitar', [LgpdController::class, 'registrarPublico'])->middleware('throttle:5,10')->name('lgpd.publico.registrar');

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

        // PCA (art. 12, VII)
        Route::get('/pca', [PcaController::class, 'index'])->middleware('can:pca.ver')->name('pca.index');
        Route::get('/pca/{plano}', [PcaController::class, 'show'])->middleware('can:pca.ver')->name('pca.show');
        Route::middleware('can:pca.gerenciar')->group(function () {
            Route::post('/pca', [PcaController::class, 'store'])->name('pca.store');
            Route::post('/pca/{plano}/itens', [PcaController::class, 'salvarItem'])->name('pca.itens.store');
            Route::put('/pca/{plano}/itens/{item}', [PcaController::class, 'salvarItem'])->name('pca.itens.update');
            Route::delete('/pca/{plano}/itens/{item}', [PcaController::class, 'excluirItem'])->name('pca.itens.destroy');
            Route::post('/pca/{plano}/enviar-aprovacao', [PcaController::class, 'enviarAprovacao'])->name('pca.enviar');
        });
        Route::post('/pca/{plano}/aprovar', [PcaController::class, 'aprovar'])->middleware('can:pca.aprovar')->name('pca.aprovar');

        // Demandas (DFD)
        Route::get('/demandas', [DemandaController::class, 'index'])->middleware('can:demandas.ver')->name('demandas.index');
        Route::middleware('can:demandas.criar')->group(function () {
            Route::get('/demandas/nova', [DemandaController::class, 'create'])->name('demandas.create');
            Route::post('/demandas', [DemandaController::class, 'store'])->name('demandas.store');
        });
        Route::patch('/demandas/{demanda}/analisar', [DemandaController::class, 'analisar'])->middleware('can:pca.gerenciar')->name('demandas.analisar');

        // Processos
        Route::get('/processos', [ProcessoController::class, 'index'])->middleware('can:processos.ver')->name('processos.index');
        Route::middleware('can:processos.gerenciar')->group(function () {
            Route::get('/processos/novo', [ProcessoController::class, 'create'])->name('processos.create');
            Route::post('/processos', [ProcessoController::class, 'store'])->name('processos.store');
            Route::get('/processos/{processo}/editar', [ProcessoController::class, 'edit'])->name('processos.edit');
            Route::put('/processos/{processo}', [ProcessoController::class, 'update'])->name('processos.update');
            Route::post('/processos/{processo}/itens', [ItemProcessoController::class, 'store'])->name('processos.itens.store');
            Route::delete('/processos/{processo}/itens/{item}', [ItemProcessoController::class, 'destroy'])->name('processos.itens.destroy');
            Route::patch('/processos/{processo}/itens/{item}/metodo', [ItemProcessoController::class, 'metodo'])->name('processos.itens.metodo');
            Route::patch('/processos/{processo}/itens/{item}/resultado', [ItemProcessoController::class, 'resultado'])->name('processos.itens.resultado');
            Route::post('/processos/{processo}/itens/{item}/cotacoes', [ItemProcessoController::class, 'adicionarCotacao'])->name('processos.cotacoes.store');
            Route::patch('/processos/{processo}/cotacoes/{cotacao}', [ItemProcessoController::class, 'desconsiderarCotacao'])->name('processos.cotacoes.desconsiderar');
            Route::delete('/processos/{processo}/cotacoes/{cotacao}', [ItemProcessoController::class, 'excluirCotacao'])->name('processos.cotacoes.destroy');
        });
        Route::get('/processos/{processo}', [ProcessoController::class, 'show'])->middleware('can:processos.ver')->name('processos.show');
        Route::post('/processos/{processo}/tramitar', [ProcessoController::class, 'tramitar'])->middleware('can:processos.ver')->name('processos.tramitar');
        Route::get('/processos/{processo}/documentos/{tipo}', [DocumentoProcessoController::class, 'edit'])->middleware('can:processos.ver')->name('documentos.edit');
        Route::post('/processos/{processo}/documentos/{tipo}', [DocumentoProcessoController::class, 'salvar'])->middleware('can:processos.ver')->name('documentos.salvar');
        Route::get('/limite-dispensa', DispensaController::class)->middleware('can:processos.ver')->name('dispensa.simulador');

        // Fornecedores
        Route::get('/fornecedores', [FornecedorController::class, 'index'])->middleware('can:fornecedores.ver')->name('fornecedores.index');
        Route::middleware('can:fornecedores.gerenciar')->group(function () {
            Route::get('/fornecedores/novo', [FornecedorController::class, 'create'])->name('fornecedores.create');
            Route::post('/fornecedores', [FornecedorController::class, 'store'])->name('fornecedores.store');
            Route::get('/fornecedores/{fornecedor}/editar', [FornecedorController::class, 'edit'])->name('fornecedores.edit');
            Route::put('/fornecedores/{fornecedor}', [FornecedorController::class, 'update'])->name('fornecedores.update');
            Route::post('/fornecedores/{fornecedor}/sancoes', [FornecedorController::class, 'registrarSancao'])->name('fornecedores.sancoes.store');
        });

        // Contratos
        Route::get('/contratos', [ContratoController::class, 'index'])->middleware('can:contratos.ver')->name('contratos.index');
        Route::middleware('can:contratos.gerenciar')->group(function () {
            Route::get('/contratos/novo', [ContratoController::class, 'create'])->name('contratos.create');
            Route::post('/contratos', [ContratoController::class, 'store'])->name('contratos.store');
            Route::post('/contratos/{contrato}/termos', [ContratoController::class, 'registrarTermo'])->name('contratos.termos.store');
        });
        Route::get('/contratos/{contrato}', [ContratoController::class, 'show'])->middleware('can:contratos.ver')->name('contratos.show');

        // PNCP
        Route::get('/pncp', [PncpController::class, 'index'])->middleware('can:processos.ver')->name('pncp.index');
        Route::middleware('can:pncp.enviar')->group(function () {
            Route::post('/pncp/contratos/{contrato}', [PncpController::class, 'publicarContrato'])->name('pncp.contrato');
            Route::post('/pncp/termos/{termo}', [PncpController::class, 'publicarTermo'])->name('pncp.termo');
            Route::post('/pncp/envios/{envio}/reenviar', [PncpController::class, 'reenviar'])->name('pncp.reenviar');
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
