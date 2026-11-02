<x-layouts.app titulo="Trilha de auditoria">
    <div>
        <h1 class="h1">Trilha de auditoria</h1>
        <p class="sub">Tudo o que foi feito no sistema, por quem, quando e de onde. Os registros não podem ser alterados nem apagados, nem pelo administrador.</p>
    </div>

    <form method="GET" class="card filtros" role="search">
        <div class="campo">
            <label for="f-evento">Tipo de evento</label>
            <select id="f-evento" name="evento" class="input">
                <option value="">Todos</option>
                @foreach ($eventos as $valor => $rotulo)
                    <option value="{{ $valor }}" @selected(request('evento') === $valor)>{{ $rotulo }}</option>
                @endforeach
            </select>
        </div>
        <div class="campo">
            <label for="f-usuario">Usuário</label>
            <input id="f-usuario" name="usuario" value="{{ request('usuario') }}" class="input" placeholder="Nome ou e-mail">
        </div>
        <div class="campo">
            <label for="f-busca">Descrição contém</label>
            <input id="f-busca" name="busca" value="{{ request('busca') }}" class="input">
        </div>
        <x-campo nome="de" rotulo="De" tipo="date" :valor="request('de')" />
        <x-campo nome="ate" rotulo="Até" tipo="date" :valor="request('ate')" />
        <div class="btns">
            <button class="btn btn-s" type="submit"><x-icone nome="busca" />Filtrar</button>
            @if (request()->hasAny(['evento', 'usuario', 'busca', 'de', 'ate']))<a class="btn btn-s" href="{{ route('auditoria.index') }}">Limpar</a>@endif
        </div>
    </form>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Quando</th><th>Evento</th><th>O que aconteceu</th><th>Quem</th>@if (auth()->user()->orgao_id === null)<th>Órgão</th>@endif<th>IP</th></tr></thead>
                <tbody>
                    @forelse ($registros as $r)
                        @php
                            $classe = match ($r->evento) {
                                'login_falhou', 'excluido' => 'b-err',
                                'permissao', 'senha', 'seguranca' => 'b-warn',
                                'criado', 'login' => 'b-ok',
                                default => 'b-info',
                            };
                        @endphp
                        <tr>
                            <td class="small" style="white-space:nowrap">{{ $r->created_at->format('d/m/Y') }}<div class="muted">{{ $r->created_at->format('H:i:s') }}</div></td>
                            <td><span class="b {{ $classe }}">{{ $r->rotuloEvento() }}</span></td>
                            <td>
                                {{ $r->descricao }}
                                @if ($r->valores_antigos || $r->valores_novos)
                                    <details class="small" style="margin-top:4px">
                                        <summary style="cursor:pointer;color:#34729C;font-weight:600">Ver detalhes</summary>
                                        <table class="tbl" style="margin-top:6px;font-size:12.5px">
                                            <thead><tr><th>Campo</th><th>Antes</th><th>Depois</th></tr></thead>
                                            <tbody>
                                                @foreach (array_unique(array_merge(array_keys($r->valores_antigos ?? []), array_keys($r->valores_novos ?? []))) as $campo)
                                                    <tr>
                                                        <td><code>{{ $campo }}</code></td>
                                                        <td class="muted">{{ json_encode($r->valores_antigos[$campo] ?? null, JSON_UNESCAPED_UNICODE) }}</td>
                                                        <td>{{ json_encode($r->valores_novos[$campo] ?? null, JSON_UNESCAPED_UNICODE) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </details>
                                @endif
                            </td>
                            <td class="small">{{ $r->usuario?->name ?? 'Sistema' }}<div class="muted">{{ $r->usuario?->email }}</div></td>
                            @if (auth()->user()->orgao_id === null)<td class="sigla">{{ $r->orgao?->sigla ?? '—' }}</td>@endif
                            <td class="muted small">{{ $r->ip ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="vazio"><b>Nenhum registro encontrado</b>Ajuste os filtros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="paginacao">{{ $registros->links() }}</div>
</x-layouts.app>
