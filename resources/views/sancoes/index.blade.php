<x-layouts.app titulo="Processos sancionadores">
    <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;align-items:flex-start">
        <div><h1 class="h1">Processos sancionadores</h1><p class="sub">Apuração de infração de licitante ou contratado, com defesa, decisão, recurso e comunicação ao CEIS/CNEP. <span class="lei">Arts. 155 a 163</span></p></div>
        @can('sancoes.conduzir')
            <div class="btns"><a class="btn btn-p" href="{{ route('sancoes.create') }}">Instaurar processo</a><a class="btn btn-s" href="{{ route('sancoes.ceis.exportar') }}"><x-icone nome="download" />Planilha CEIS/CNEP</a></div>
        @endcan
    </div>

    @if ($ceisPendentes->isNotEmpty())
        <div class="flash flash-aviso" role="status"><x-icone nome="alerta" style="flex:none" /><div><b>{{ $ceisPendentes->count() }} sanção(ões) a informar no CEIS/CNEP</b> (prazo de 15 dias úteis, <span class="lei">art. 161</span>):
            @foreach ($ceisPendentes as $p) <a href="{{ route('sancoes.show', $p) }}">{{ $p->numero }}</a> até {{ $p->prazo_ceis?->format('d/m/Y') }}@if ($p->ceisAtrasado()) <b style="color:#9F1D1D">(atrasado)</b>@endif{{ $loop->last ? '.' : ';' }}@endforeach</div></div>
    @endif

    <form method="GET" class="btns" style="margin:8px 0">
        <select name="situacao" class="input" style="width:auto" aria-label="Filtrar por situação" onchange="this.form.submit()"><option value="">Todas as situações</option>@foreach (\App\Models\ProcessoSancionador::SITUACOES as $v => $r)<option value="{{ $v }}" @selected($situacao === $v)>{{ $r }}</option>@endforeach</select>
        <noscript><button class="btn btn-s btn-sm">Filtrar</button></noscript>
    </form>

    <div class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Processo</th><th>Fornecedor</th><th>Infrações</th><th>Sanção</th><th>Situação</th><th>Próximo prazo</th><th></th></tr></thead>
        <tbody>
            @forelse ($processos as $p)
                @php($prazo = match ($p->situacao) { 'defesa' => $p->prazo_defesa, 'alegacoes' => $p->prazo_alegacoes, 'decidido' => $p->recurso_decidido_em ? null : $p->prazo_recurso, 'recurso' => $p->prazo_decisao_recurso ?? $p->prazo_reconsideracao, 'definitivo' => $p->ceis_informado_em ? null : $p->prazo_ceis, default => null })
                <tr><td><b>{{ $p->numero }}</b></td><td>{{ $p->fornecedor->razao_social }}</td><td>art. 155, {{ implode(', ', $p->infracoes) }}</td>
                    <td>{{ \Illuminate\Support\Str::before(\App\Models\SancaoFornecedor::TIPOS[$p->sancao_tipo ?? $p->sancao_proposta] ?? '', ' (') }}@if (! $p->sancao_tipo) <span class="muted small">(proposta)</span>@endif</td>
                    <td><span class="b {{ in_array($p->situacao, ['definitivo']) ? 'b-err' : (in_array($p->situacao, ['arquivado', 'reabilitado']) ? 'b-mute' : 'b-info') }}">{{ \App\Models\ProcessoSancionador::SITUACOES[$p->situacao] }}</span></td>
                    <td>{{ $prazo?->format('d/m/Y') ?? '–' }}</td><td class="acoes"><a class="btn btn-s btn-sm" href="{{ route('sancoes.show', $p) }}">Abrir</a></td></tr>
            @empty
                <tr><td colspan="7" class="vazio"><b>Nenhum processo sancionador</b></td></tr>
            @endforelse
        </tbody>
    </table></div></div>
    <div class="paginacao">{{ $processos->links() }}</div>
</x-layouts.app>
