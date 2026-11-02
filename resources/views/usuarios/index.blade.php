<x-layouts.app titulo="Usuários e perfis">
    <div class="row">
        <div>
            <h1 class="h1">Usuários e perfis</h1>
            <p class="sub">Quem acessa o sistema e o que cada um pode fazer. Todo cadastro e mudança de perfil fica na auditoria.</p>
        </div>
        @can('usuarios.gerenciar')
            <a href="{{ route('usuarios.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo usuário</a>
        @endcan
    </div>

    <form method="GET" class="card filtros" role="search">
        <div class="campo">
            <label for="f-busca">Nome ou e-mail</label>
            <input id="f-busca" name="busca" value="{{ request('busca') }}" class="input">
        </div>
        <div class="campo">
            <label for="f-perfil">Perfil</label>
            <select id="f-perfil" name="perfil" class="input">
                <option value="">Todos</option>
                @foreach (\App\Models\User::PERFIS as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected(request('perfil') === $valor)>{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label for="f-situacao">Situação</label>
            <select id="f-situacao" name="situacao" class="input">
                <option value="">Todas</option>
                <option value="ativos" @selected(request('situacao') === 'ativos')>Ativos</option>
                <option value="inativos" @selected(request('situacao') === 'inativos')>Inativos</option>
            </select>
        </div>
        <div class="btns">
            <button class="btn btn-s" type="submit"><x-icone nome="busca" />Filtrar</button>
            @if (request()->hasAny(['busca', 'perfil', 'situacao']))<a class="btn btn-s" href="{{ route('usuarios.index') }}">Limpar</a>@endif
        </div>
    </form>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Usuário</th>@if (auth()->user()->orgao_id === null)<th>Órgão</th>@endif<th>Perfil</th><th>2 etapas</th><th>Último acesso</th><th>Situação</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @forelse ($usuarios as $u)
                        <tr>
                            <td>
                                <div class="arvore-linha">
                                    <span class="avatar" style="width:34px;height:34px;font-size:12px" aria-hidden="true">{{ $u->iniciais() }}</span>
                                    <div><b>{{ $u->name }}</b><div class="muted small">{{ $u->email }}@if ($u->cpf) · CPF {{ $u->cpfMascarado() }}@endif</div></div>
                                </div>
                            </td>
                            @if (auth()->user()->orgao_id === null)<td class="sigla">{{ $u->orgao?->sigla ?? 'Geral' }}</td>@endif
                            <td><span class="b b-info">{{ \App\Models\User::PERFIS[$u->perfilPrincipal()] ?? 'Sem perfil' }}</span></td>
                            <td>@if ($u->temDoisFatoresAtivo())<span class="b b-ok">Ativa</span>@else<span class="b {{ $u->exigeDoisFatores() ? 'b-warn' : 'b-mute' }}">Não ativada</span>@endif</td>
                            <td class="muted small">{{ $u->ultimo_acesso_em?->format('d/m/Y H:i') ?? 'Nunca acessou' }}</td>
                            <td>@if ($u->ativo)<span class="b b-ok">Ativo</span>@else<span class="b b-mute">Inativo</span>@endif</td>
                            <td class="acoes">
                                @can('alterar-usuario', $u)
                                    <div class="btns" style="justify-content:flex-end;flex-wrap:nowrap">
                                        <a href="{{ route('usuarios.edit', $u) }}" class="btn btn-s btn-sm"><x-icone nome="editar" tamanho="16" />Editar</a>
                                        <form method="POST" action="{{ route('usuarios.link-senha', $u) }}">@csrf
                                            <button class="btn btn-s btn-sm" type="submit" title="Enviar por e-mail o link para criar ou trocar a senha"><x-icone nome="email" tamanho="16" /><span class="sr-only">Enviar link de senha</span></button>
                                        </form>
                                        @unless ($u->is(auth()->user()))
                                            <form method="POST" action="{{ route('usuarios.ativo', $u) }}" data-confirmar="{{ $u->ativo ? 'Desativar o acesso de '.$u->name.'? A sessão dele será encerrada na hora.' : 'Reativar o acesso de '.$u->name.'?' }}" onsubmit="return confirm(this.dataset.confirmar)">@csrf @method('PATCH')
                                                <button class="btn {{ $u->ativo ? 'btn-d' : 'btn-s' }} btn-sm" type="submit"><x-icone nome="liga" tamanho="16" />{{ $u->ativo ? 'Desativar' : 'Reativar' }}</button>
                                            </form>
                                        @endunless
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="vazio"><b>Nenhum usuário encontrado</b>Ajuste os filtros ou cadastre um novo usuário.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="paginacao">{{ $usuarios->links() }}</div>
</x-layouts.app>
