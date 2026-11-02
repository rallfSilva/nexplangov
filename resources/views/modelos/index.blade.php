<x-layouts.app titulo="Modelos de documentos">
    <div class="row">
        <div><h1 class="h1">Modelos de documentos</h1><p class="sub">Biblioteca do órgão. Os modelos NexMind são ponto de partida e precisam ser revisados pela assessoria jurídica. Você pode editar, duplicar e desativar.</p></div>
        <a href="{{ route('modelos.create', ['tipo' => $tipo]) }}" class="btn btn-p"><x-icone nome="mais" />Novo modelo</a>
    </div>
    <form method="GET" class="card filtros"><div class="campo"><label for="f-tipo">Tipo de peça</label><select id="f-tipo" name="tipo" class="input" onchange="this.form.submit()"><option value="">Todos</option>@foreach ($tipos as $k => $d)<option value="{{ $k }}" @selected($tipo === $k)>{{ $d['rotulo'] }}</option>@endforeach</select></div></form>
    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Modelo</th><th>Peça</th><th>Vale para</th><th>Versão</th><th>Situação</th><th class="acoes"></th></tr></thead>
        <tbody>
            @forelse ($modelos as $m)
                <tr>
                    <td><b>{{ $m->titulo }}</b>@if ($m->padrao_nexmind)<div class="muted small">Modelo NexMind</div>@endif</td>
                    <td>{{ $tipos[$m->tipo]['rotulo'] ?? $m->tipo }}</td>
                    <td class="small">{{ \App\Models\Fluxo::APLICACOES[$m->aplicacao] ?? $m->aplicacao }}@if ($m->categoria) · {{ \App\Licitacao\Enums\CategoriaProcesso::from($m->categoria)->rotulo() }}@endif</td>
                    <td class="valor">v{{ $m->versao }}</td>
                    <td>@if ($m->ativo)<span class="b b-ok">Ativo</span>@else<span class="b b-mute">Inativo</span>@endif</td>
                    <td class="acoes"><a class="btn btn-s btn-sm" href="{{ route('modelos.edit', $m) }}"><x-icone nome="editar" tamanho="16" />Abrir</a></td>
                </tr>
            @empty
                <tr><td colspan="6" class="vazio"><b>Nenhum modelo</b></td></tr>
            @endforelse
        </tbody>
    </table></div></div>
</x-layouts.app>
