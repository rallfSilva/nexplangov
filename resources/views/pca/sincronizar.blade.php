<x-layouts.app :titulo="'PCA '.$plano->ano.' no PNCP'">
    <div class="row">
        <div>
            <a href="{{ route('pca.show', $plano) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />PCA {{ $plano->ano }}</a>
            <h1 class="h1" style="margin-top:8px">PCA {{ $plano->ano }} no PNCP</h1>
            <p class="sub">Confere, unidade por unidade, o que o PNCP tem com o plano do sistema. <span class="lei">art. 12, § 1º, e art. 174</span></p>
        </div>
        @can('pncp.enviar')
            <div class="btns">
                <form method="POST" action="{{ route('pca.sincronizar.executar', $plano) }}">@csrf<button class="btn btn-s" type="submit"><x-icone nome="relogio" />Sincronizar agora</button></form>
                @if ($plano->situacao === 'aprovado')
                    <form method="POST" action="{{ route('pncp.pca', $plano) }}">@csrf<button class="btn btn-p" type="submit"><x-icone nome="pncp" />Atualizar no PNCP</button></form>
                @endif
            </div>
        @endcan
    </div>

    @forelse ($partes as $p)
        @php($s = $p->sincronizacao)
        <section class="card">
            <div class="row"><h2 style="margin:0">{{ $p->unidade?->sigla }} <span class="muted small">{{ $p->unidade?->nome }} · {{ $p->numero_controle_pncp }}</span></h2>
                <span class="small muted" style="margin-left:auto">{{ $p->sincronizado_em ? 'Conferido em '.$p->sincronizado_em->format('d/m/Y H:i') : 'Ainda não conferido' }}</span></div>
            @if ($s)
                @if (! $s['novos'] && ! $s['removidos'] && ! $s['alterados'])
                    <p class="small" style="margin-top:8px"><span class="b b-ok">Em dia</span> Os {{ $s['iguais'] }} itens do PNCP batem com o plano.</p>
                @else
                    <ul class="small" style="margin-top:8px">
                        <li>{{ $s['iguais'] }} {{ $s['iguais'] === 1 ? 'item igual' : 'itens iguais' }}.</li>
                        @if ($s['novos'])<li><span class="b b-info">A incluir</span> itens {{ implode(', ', $s['novos']) }}: estão no plano e não no PNCP. "Atualizar no PNCP" inclui.</li>@endif
                        @if ($s['alterados'])<li><span class="b b-warn">A retificar</span> itens {{ implode(', ', array_keys($s['alterados'])) }}: "Atualizar no PNCP" retifica, com justificativa.</li>@endif
                        @if ($s['removidos'])<li><span class="b b-err">Só no PNCP</span> itens {{ implode(', ', $s['removidos']) }}: não estão mais no plano. Exclua no PNCP se a revisão do plano os retirou.</li>@endif
                    </ul>
                    @if ($s['alterados'])
                        <div class="tbl-wrap" style="margin-top:8px">
                            <table class="tbl">
                                <thead><tr><th>Item</th><th>Campo</th><th>No plano</th><th>No PNCP</th></tr></thead>
                                <tbody>
                                    @foreach ($s['alterados'] as $n => $difs)
                                        @foreach ($difs as $d)<tr><td class="sigla">{{ $n }}</td><td>{{ $d['campo'] }}</td><td>{{ \Illuminate\Support\Str::limit($d['aqui'], 80) }}</td><td>{{ \Illuminate\Support\Str::limit($d['pncp'], 80) }}</td></tr>@endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            @endif
        </section>
    @empty
        <div class="card vazio"><b>PCA ainda não publicado</b>Depois de aprovado, publique no PNCP pela tela do plano.</div>
    @endforelse
</x-layouts.app>
