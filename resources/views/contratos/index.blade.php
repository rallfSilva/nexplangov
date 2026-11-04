<x-layouts.app titulo="Contratos">
    <div class="row">
        <div><h1 class="h1">Contratos</h1><p class="sub">Vigência, gestor e fiscal, aditivos e apostilamentos com os limites da lei.</p></div>
        <div class="btns">
            <a href="{{ route('contratos.index', ['filtro' => request('filtro') === 'vencendo' ? null : 'vencendo']) }}" class="btn btn-s"><x-icone nome="relogio" />{{ request('filtro') === 'vencendo' ? 'Ver todos' : 'Vencendo em 120 dias' }}</a>
            <a href="{{ route('contratos.exportar', request()->only(['busca', 'filtro'])) }}" class="btn btn-s"><x-icone nome="planilha" />Exportar XLSX</a>
            @can('contratos.gerenciar')<a href="{{ route('contratos.create') }}" class="btn btn-p"><x-icone nome="mais" />Novo contrato</a>@endcan
        </div>
    </div>
    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Contrato</th><th>Objeto</th><th>Fornecedor</th><th class="num">Valor global</th><th>Vigência</th><th>Situação</th></tr></thead>
        <tbody>
            @forelse ($contratos as $c)
                @php($dias = $c->diasParaVencer())
                <tr>
                    <td><a class="sigla" href="{{ route('contratos.show', $c) }}">{{ $c->identificacao() }}</a></td>
                    <td>{{ \Illuminate\Support\Str::limit($c->objeto, 80) }}</td>
                    <td>{{ $c->fornecedor->razao_social }}</td>
                    <td class="num valor">{{ reais($c->valor_global) }}</td>
                    <td>{{ $c->vigencia_fim->format('d/m/Y') }}@if ($c->situacao === 'vigente')<div class="small {{ $dias <= 30 ? '' : 'muted' }}" @if ($dias <= 30) style="color:#B42318;font-weight:600" @endif>{{ $dias < 0 ? 'vencido há '.abs($dias).' dias' : 'faltam '.$dias.' dias' }}</div>@endif</td>
                    <td><span class="b {{ $c->situacao === 'vigente' ? ($dias <= 60 ? 'b-warn' : 'b-ok') : 'b-mute' }}">{{ \App\Models\Contrato::SITUACOES[$c->situacao] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="vazio"><b>Nenhum contrato</b></td></tr>
            @endforelse
        </tbody>
    </table></div></div>
    <div class="paginacao">{{ $contratos->links() }}</div>
</x-layouts.app>
