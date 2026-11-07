@php($formatos = ['pdf' => 'PDF', 'csv' => 'CSV', 'txt' => 'TXT', 'xlsx' => 'XLSX'])
@php($pct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.').'%')
<x-layouts.app titulo="Painéis gerenciais">
    <div class="row">
        <div>
            <h1 class="h1">Painéis gerenciais</h1>
            <p class="sub">Contratações por secretaria, perfil dos fornecedores e fomento ao mercado local <span class="lei">art. 11, parágrafo único; art. 40, § 2º, II</span></p>
        </div>
        <form method="GET" class="btns" style="align-items:flex-end"><div class="campo"><label for="ano">Exercício</label><input id="ano" name="ano" type="number" min="2023" max="2100" class="input" value="{{ $ano }}" style="width:110px"></div><button class="btn btn-s" type="submit">Ver</button></form>
    </div>

    @foreach (\App\Http\Controllers\RelatorioGestaoController::RELATORIOS as $chave => $rotulo)
        @php($exportar = collect($formatos)->map(fn ($r, $f) => '<a href="'.e(route('relatorios.exportar', [$chave, $f, 'ano' => $ano])).'" class="btn btn-g btn-sm">'.$r.'</a>')->implode(' '))
        <section class="card">
            <div class="row" style="align-items:flex-start"><h2>{{ $rotulo }}</h2><div class="btns" aria-label="Exportar {{ $rotulo }}">{!! $exportar !!}</div></div>

            @if ($chave === 'secretarias')
                <div class="tbl-wrap" style="margin-top:10px"><table class="tbl"><thead><tr><th>Secretaria</th><th>Processos</th><th>Em andamento</th><th>Valor</th><th>Participação</th><th>Execução do PCA</th><th>Contratos</th><th>Valor contratado</th></tr></thead><tbody>
                    @forelse ($secretarias as $u)
                        <tr><td><b>{{ $u['unidade'] }}</b></td><td>{{ $u['processos'] }}</td><td>{{ $u['andamento'] }}</td><td class="valor">{{ reais($u['valor']) }}</td><td>{{ $pct($u['participacao']) }}</td>
                            <td>{{ $u['execucao_pca'] === null ? '—' : $u['execucao_pca'].'% de '.$u['itens_pca'].' itens' }}</td><td>{{ $u['contratos'] }}</td><td class="valor">{{ reais($u['valor_contratos']) }}</td></tr>
                    @empty
                        <tr><td colspan="8" class="muted">Sem processos, PCA ou contratos em {{ $ano }}.</td></tr>
                    @endforelse
                </tbody></table></div>
            @elseif ($chave === 'fornecedores')
                <p class="hint">{{ $perfil['fornecedores'] }} fornecedor(es) com contrato em {{ $ano }}, somando {{ reais($perfil['valor']) }}. ME e EPP: {{ $pct($perfil['pct_me_epp']) }} do valor <span class="lei">art. 4º</span></p>
                <div class="g2" style="margin-top:10px">
                    @foreach (['porte' => 'Por porte', 'uf' => 'Por UF', 'municipio' => 'Por município (10 maiores)'] as $dim => $tit)
                        <div><h3 style="font-size:14px;margin:0 0 6px">{{ $tit }}</h3><div class="tbl-wrap"><table class="tbl"><thead><tr><th>{{ $tit }}</th><th>Fornecedores</th><th>Valor</th><th>%</th></tr></thead><tbody>
                            @forelse ($perfil[$dim] as $x)<tr><td>{{ $x['rotulo'] }}</td><td>{{ $x['fornecedores'] }}</td><td class="valor">{{ reais($x['valor']) }}</td><td>{{ $pct($x['pct_valor']) }}</td></tr>@empty<tr><td colspan="4" class="muted">Sem contratos.</td></tr>@endforelse
                        </tbody></table></div></div>
                    @endforeach
                </div>
            @else
                <p class="hint">Valor contratado com fornecedores de {{ $orgao?->municipio ?? 'município não informado' }}/{{ $orgao?->uf ?? '—' }}, do restante da UF e de fora. Em {{ $ano }}, ME e EPP locais receberam {{ reais($fomento['me_epp_local']) }}.</p>
                <div class="kpis" style="margin-top:10px">
                    @foreach (['local' => 'Mesmo município', 'regional' => 'Outros municípios da UF', 'fora' => 'Outras UFs', 'sem_endereco' => 'Sem endereço no cadastro'] as $k => $r)
                        <div class="card kpi"><span class="lbl">{{ $r }}</span><span class="val">{{ $pct($fomento[$k]['pct']) }}</span><span class="foot">{{ reais($fomento[$k]['valor']) }} · {{ $fomento[$k]['fornecedores'] }} fornecedor(es)</span></div>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
</x-layouts.app>
