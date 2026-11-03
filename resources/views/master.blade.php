<x-layouts.app titulo="Painel master">
    <div>
        <h1 class="h1">Painel master NexMind</h1>
        <p class="sub">Visão operacional de todos os órgãos. Mostra contagens e saúde, nunca o conteúdo dos processos.</p>
    </div>
    <div class="kpis">
        <div class="card kpi"><span class="lbl">Órgãos</span><span class="val">{{ $linhas->count() }}</span><span class="foot">{{ $linhas->where('orgao.ativo', true)->count() }} ativos</span></div>
        <div class="card kpi"><span class="lbl">Trilha de auditoria</span><span class="val">{{ $cadeiaIntegra ? 'Íntegra' : 'Violada' }}</span><span class="foot">{{ number_format($registros, 0, ',', '.') }} registros encadeados por hash @unless ($cadeiaIntegra) · primeiro problema no registro {{ $quebra }}@endunless</span></div>
        <div class="card kpi"><span class="lbl">Catálogo</span><span class="val">{{ number_format($catalogo['M'] + $catalogo['S'], 0, ',', '.') }}</span><span class="foot">{{ number_format($catalogo['M'], 0, ',', '.') }} CATMAT · {{ number_format($catalogo['S'], 0, ',', '.') }} CATSER</span></div>
    </div>
    <section class="card"><h2>Órgãos</h2>
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Órgão</th><th>PNCP</th><th class="num">Usuários</th><th class="num">Sem 2FA</th><th class="num">Processos em andamento</th><th class="num">Contratos vigentes</th><th class="num">Erros PNCP</th><th>Última atividade</th></tr></thead><tbody>
            @foreach ($linhas as $l)
                <tr><td><b>{{ $l['orgao']->sigla }}</b>@unless ($l['orgao']->ativo) <span class="b b-mute">inativo</span>@endunless<div class="small muted">{{ $l['orgao']->nome }}</div></td>
                    <td>{{ $l['orgao']->pncp_ambiente === 'producao' ? 'Produção' : 'Homologação' }}</td>
                    <td class="num">{{ $l['ativos'] }}</td><td class="num">@if ($l['sem_2fa'])<span class="b b-warn">{{ $l['sem_2fa'] }}</span>@else 0 @endif</td>
                    <td class="num">{{ $l['processos'] }}</td><td class="num">{{ $l['contratos'] }}</td><td class="num">@if ($l['pncp_erros'])<span class="b b-err">{{ $l['pncp_erros'] }}</span>@else 0 @endif</td>
                    <td class="small">{{ $l['ultima'] ? \Illuminate\Support\Carbon::parse($l['ultima'])->format('d/m/Y H:i') : 'sem atividade' }}</td></tr>
            @endforeach
        </tbody></table></div>
    </section>
</x-layouts.app>
