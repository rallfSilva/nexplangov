<x-layouts.app titulo="Unidades e organograma">
    <div class="row">
        <div>
            <h1 class="h1">Unidades e organograma</h1>
            <p class="sub">Setores do órgão em árvore, com responsável e o código de unidade compradora usado no PNCP.</p>
        </div>
        <div class="btns">
            @can('unidades.gerenciar')
                <a href="{{ route('unidades.create', ['orgao' => $orgaoId]) }}" class="btn btn-p"><x-icone nome="mais" />Nova unidade</a>
            @endcan
        </div>
    </div>

    <form method="GET" class="card filtros" role="search">
        @if ($orgaos->isNotEmpty())
            <div class="campo">
                <label for="f-orgao">Órgão</label>
                <select id="f-orgao" name="orgao" class="input" onchange="this.form.submit()">
                    @foreach ($orgaos as $o)
                        <option value="{{ $o->id }}" @selected($o->id === $orgaoId)>{{ $o->sigla }} · {{ $o->nome }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="campo">
            <label for="f-busca">Buscar por sigla ou nome</label>
            <input id="f-busca" name="busca" value="{{ request('busca') }}" class="input" placeholder="Ex.: DTI">
        </div>
        <div class="btns">
            <button class="btn btn-s" type="submit"><x-icone nome="busca" />Buscar</button>
            @if (request('busca'))<a class="btn btn-s" href="{{ route('unidades.index', ['orgao' => $orgaoId]) }}">Limpar</a>@endif
        </div>
    </form>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Unidade</th><th>Tipo</th><th>Chefe</th><th>Código PNCP</th><th>Situação</th><th class="acoes"><span class="sr-only">Ações</span></th></tr></thead>
                <tbody>
                    @php($linhas = $arvore ?? $unidades->map(fn ($u) => ['unidade' => $u, 'nivel' => 0]))
                    @forelse ($linhas as ['unidade' => $unidade, 'nivel' => $nivel])
                        <tr>
                            <td>
                                <div class="arvore-linha">
                                    @if ($nivel > 0)
                                        <span class="arvore-recuo" style="width: {{ ($nivel - 1) * 24 }}px"></span><span class="arvore-ramo" aria-hidden="true"></span>
                                    @endif
                                    <div>
                                        <span class="sigla">{{ $unidade->sigla }}</span> <span>{{ $unidade->nome }}</span>
                                        @if ($arvore === null && $unidade->superior)<div class="muted small">subordinada a {{ $unidade->superior->sigla }}</div>@endif
                                    </div>
                                </div>
                            </td>
                            <td class="muted">{{ \App\Models\Unidade::TIPOS[$unidade->tipo] ?? $unidade->tipo }}</td>
                            <td>@if ($unidade->chefe){{ $unidade->chefe->name }}@else<span class="b b-warn">Sem chefe</span>@endif</td>
                            <td class="muted">{{ $unidade->codigo_unidade_pncp ?? '—' }}</td>
                            <td>@if ($unidade->ativo)<span class="b b-ok">Ativa</span>@else<span class="b b-mute">Inativa</span>@endif</td>
                            <td class="acoes">
                                @can('unidades.gerenciar')
                                    <a href="{{ route('unidades.create', ['orgao' => $unidade->orgao_id, 'superior' => $unidade->id]) }}" class="btn btn-s btn-sm" title="Nova unidade subordinada a {{ $unidade->sigla }}"><x-icone nome="mais" tamanho="16" /><span class="sr-only">Nova subordinada</span></a>
                                    <a href="{{ route('unidades.edit', $unidade) }}" class="btn btn-s btn-sm"><x-icone nome="editar" tamanho="16" />Editar</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="vazio"><b>Nenhuma unidade encontrada</b>@can('unidades.gerenciar')Cadastre a primeira unidade, começando pelo gabinete.@endcan</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
