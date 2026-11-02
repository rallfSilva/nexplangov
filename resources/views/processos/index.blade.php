<x-layouts.app titulo="Processos">
    <div class="row">
        <div>
            <h1 class="h1">Processos de contratação</h1>
            <p class="sub">Licitações e contratações diretas, da fase preparatória ao contrato.</p>
        </div>
        @can('processos.gerenciar')<a href="{{ route('processos.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo processo</a>@endcan
    </div>

    <form method="GET" class="card filtros" role="search">
        <div class="campo"><label for="f-busca">Número ou objeto</label><input id="f-busca" name="busca" value="{{ request('busca') }}" class="input"></div>
        <div class="campo">
            <label for="f-mod">Modalidade</label>
            <select id="f-mod" name="modalidade" class="input">
                <option value="">Todas</option>
                @foreach (\App\Licitacao\Enums\Modalidade::opcoes() as $v => $r)<option value="{{ $v }}" @selected((int) request('modalidade') === $v)>{{ $r }}</option>@endforeach
            </select>
        </div>
        <div class="campo">
            <label for="f-sit">Situação</label>
            <select id="f-sit" name="situacao" class="input">
                <option value="">Todas</option>
                @foreach (\App\Licitacao\Enums\SituacaoProcesso::cases() as $s)<option value="{{ $s->value }}" @selected(request('situacao') === $s->value)>{{ $s->rotulo() }}</option>@endforeach
            </select>
        </div>
        <div class="btns"><button class="btn btn-s" type="submit"><x-icone nome="busca" />Filtrar</button></div>
    </form>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Processo</th><th>Objeto</th><th>Modalidade</th><th class="num">Valor estimado</th><th>Situação</th></tr></thead>
                <tbody>
                    @forelse ($processos as $p)
                        <tr>
                            <td><a class="sigla" href="{{ route('processos.show', $p) }}">{{ $p->identificacao() }}</a><div class="muted small">{{ $p->unidade?->sigla }}</div></td>
                            <td>{{ \Illuminate\Support\Str::limit($p->objeto, 110) }}</td>
                            <td class="muted">{{ $p->modalidadeEnum()->rotulo() }}</td>
                            <td class="num valor">{{ $p->orcamento_sigiloso && ! auth()->user()->can('processos.gerenciar') ? 'Sigiloso' : reais($p->valor_estimado) }}</td>
                            <td><span class="b {{ $p->situacaoEnum()->badge() }}">{{ $p->situacaoEnum()->rotulo() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="vazio"><b>Nenhum processo</b>Abra um processo a partir de uma demanda aceita.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="paginacao">{{ $processos->links() }}</div>
</x-layouts.app>
