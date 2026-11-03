@php
    $user = auth()->user();
    $nav = fn (string ...$padroes) => request()->routeIs(...$padroes) ? 'on' : '';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo ?? 'NexPlanGov' }} · NexPlanGov</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<a href="#conteudo" class="sr-only focus:not-sr-only">Pular para o conteúdo</a>
<div class="app">
    <aside class="side" aria-label="Menu principal">
        <a href="{{ route('painel') }}" class="brand" aria-label="NexPlanGov, ir para o painel">
            <img src="{{ asset('marca/logo-nexplangov-branco.svg') }}" alt="NexPlanGov" width="200" height="43">
        </a>
        <nav class="nav">
            <span class="cap">Visão geral</span>
            <a href="{{ route('painel') }}" class="{{ $nav('painel') }}"><x-icone nome="painel" />Painel</a>

            <span class="cap">Planejamento</span>
            @can('pca.ver')
                <a href="{{ route('pca.index') }}" class="{{ $nav('pca.*') }}"><x-icone nome="calendario" />Plano de contratações (PCA)</a>
            @endcan
            @can('demandas.ver')
                <a href="{{ route('demandas.index') }}" class="{{ $nav('demandas.*') }}"><x-icone nome="demanda" />Demandas (DFD)</a>
            @endcan

            <span class="cap">Contratação</span>
            @can('processos.ver')
                <a href="{{ route('processos.index') }}" class="{{ $nav('processos.*', 'documentos.*') }}"><x-icone nome="processo" />Processos</a>
                <a href="{{ route('dispensa.simulador') }}" class="{{ $nav('dispensa.*') }}"><x-icone nome="cifrao" />Limite de dispensa</a>
            @endcan
            @can('catalogo.ver')
                <a href="{{ route('catalogo.index') }}" class="{{ $nav('catalogo.*') }}"><x-icone nome="busca" />Catálogo CATMAT/CATSER</a>
            @endcan
            @can('fornecedores.ver')
                <a href="{{ route('fornecedores.index') }}" class="{{ $nav('fornecedores.*') }}"><x-icone nome="fornecedor" />Fornecedores</a>
            @endcan
            @can('contratos.ver')
                <a href="{{ route('atas.index') }}" class="{{ $nav('atas.*') }}"><x-icone nome="contrato" />Atas de registro de preços</a>
            @endcan
            @can('requisicoes.ver')
                <a href="{{ route('requisicoes.index') }}" class="{{ $nav('requisicoes.*') }}"><x-icone nome="demanda" />Requisições de fornecimento</a>
            @endcan
            @can('contratos.ver')
                <a href="{{ route('ordens.index') }}" class="{{ $nav('ordens.*') }}"><x-icone nome="processo" />Ordens de fornecimento</a>
            @endcan
            @can('irp.ver')
                <a href="{{ route('irp.index') }}" class="{{ $nav('irp.*') }}"><x-icone nome="liga" />Intenção de registro de preços</a>
            @endcan
            @can('contratos.ver')
                <a href="{{ route('contratos.index') }}" class="{{ $nav('contratos.*') }}"><x-icone nome="contrato" />Contratos</a>
            @endcan
            @canany(['pncp.enviar', 'processos.ver'])
                <a href="{{ route('pncp.index') }}" class="{{ $nav('pncp.*') }}"><x-icone nome="pncp" />Publicações no PNCP</a>
            @endcanany

            <span class="cap">Administração</span>
            @can('governanca.ver')
                <a href="{{ route('governanca') }}" class="{{ $nav('governanca') }}"><x-icone nome="balanca" />Governança</a>
            @endcan
            @can('capacitacao.ver')
                <a href="{{ route('capacitacao.index') }}" class="{{ $nav('capacitacao.*') }}"><x-icone nome="cargos" />Capacitação</a>
            @endcan
            @can('master.ver')
                <a href="{{ route('master') }}" class="{{ $nav('master') }}"><x-icone nome="painel" />Painel master</a>
            @endcan
            @can('orgaos.gerenciar')
                <a href="{{ route('orgaos.index') }}" class="{{ $nav('orgaos.*') }}"><x-icone nome="predio" />Órgãos</a>
                <a href="{{ route('configuracoes.index') }}" class="{{ $nav('configuracoes.*') }}"><x-icone nome="engrenagem" />Valores da lei</a>
            @endcan
            @can('modelos.gerenciar')
                <a href="{{ route('modelos.index') }}" class="{{ $nav('modelos.*') }}"><x-icone nome="pasta" />Modelos de documentos</a>
            @endcan
            @can('fluxos.gerenciar')
                <a href="{{ route('fluxos.index') }}" class="{{ $nav('fluxos.*') }}"><x-icone nome="liga" />Fluxos de documentos</a>
            @endcan
            @can('unidades.ver')
                <a href="{{ route('unidades.index') }}" class="{{ $nav('unidades.*') }}"><x-icone nome="organograma" />Unidades</a>
            @endcan
            @can('usuarios.ver')
                <a href="{{ route('usuarios.index') }}" class="{{ $nav('usuarios.*') }}"><x-icone nome="usuarios" />Usuários e perfis</a>
            @endcan
            @can('lgpd.gerenciar')
                <a href="{{ route('lgpd.index') }}" class="{{ $nav('lgpd.*') }}"><x-icone nome="privacidade" />LGPD</a>
            @endcan
            @can('auditoria.ver')
                <a href="{{ route('auditoria.index') }}" class="{{ $nav('auditoria.*') }}"><x-icone nome="auditoria" />Trilha de auditoria</a>
            @endcan
            <a href="{{ route('seguranca') }}" class="{{ $nav('seguranca') }}"><x-icone nome="escudo" />Minha segurança</a>
        </nav>
        <div class="foot">
            Lei nº 14.133/2021<br>
            Planejamento, contratação e contratos com publicação no PNCP.
        </div>
    </aside>

    <div class="main">
        <header class="top">
            <div class="org">
                <span class="chip c-navy"><x-icone nome="predio" /></span>
                <div>
                    <b>{{ $user->orgao?->sigla ?? 'Todos os órgãos' }}</b><br>
                    <span>{{ $user->orgao?->nome ?? 'Administração geral do sistema' }}</span>
                </div>
            </div>
            <div class="me">
                <div class="who">
                    <b>{{ $user->name }}</b>
                    <span>{{ \App\Models\User::PERFIS[$user->perfilPrincipal()] ?? 'Sem perfil' }}</span>
                </div>
                <span class="avatar" aria-hidden="true">{{ $user->iniciais() }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="icobtn" title="Sair do sistema" aria-label="Sair do sistema"><x-icone nome="sair" /></button>
                </form>
            </div>
        </header>

        <main id="conteudo" class="content entrar">
            <x-flash />
            {{ $slot }}
        </main>

        <x-rodape />
    </div>
</div>
</body>
</html>
