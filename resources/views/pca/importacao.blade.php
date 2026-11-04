@php($cat = \App\Licitacao\Enums\CategoriaProcesso::opcoes())
@php($rotulo = ['ok' => ['Será importada', 'b-ok'], 'erro' => ['Com erro', 'b-err'], 'duplicada' => ['Duplicada', 'b-warn']])
<x-layouts.app :titulo="'Importação de planilha · PCA '.$plano->ano">
    <div class="row">
        <div>
            <a href="{{ route('pca.show', $plano) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />PCA {{ $plano->ano }}</a>
            <h1 class="h1" style="margin-top:8px">Importação de planilha <span class="b {{ $imp->pendente() ? 'b-info' : ($imp->situacao === 'confirmada' ? 'b-ok' : 'b-mute') }}" style="vertical-align:middle">{{ \App\Models\ImportacaoPca::SITUACOES[$imp->situacao] }}</span></h1>
            <p class="sub">{{ $imp->arquivo_nome }} · enviada por {{ $imp->usuario?->name ?? '—' }} em {{ $imp->created_at->format('d/m/Y H:i') }}@if ($imp->confirmada_em) · importada em {{ $imp->confirmada_em->format('d/m/Y H:i') }}@endif</p>
        </div>
        @if ($imp->pendente())
            <div class="btns">
                <form method="POST" action="{{ route('pca.importacoes.cancelar', [$plano, $imp]) }}">@csrf<button class="btn btn-s" type="submit">Cancelar</button></form>
                @if ($imp->validas > 0)
                    <form method="POST" action="{{ route('pca.importacoes.confirmar', [$plano, $imp]) }}" onsubmit="return confirm('Incluir {{ $imp->validas }} item(ns) no PCA {{ $plano->ano }}?')">@csrf<button class="btn btn-p" type="submit"><x-icone nome="ok" />Importar {{ $imp->validas }} item(ns)</button></form>
                @endif
            </div>
        @endif
    </div>

    <div class="aviso-lei" role="note">Cada linha passa pelas mesmas conferências de um item digitado na tela. Linhas com erro e duplicadas ficam de fora; corrija a planilha e envie de novo se quiser incluí-las. <span class="lei">art. 12, VII</span></div>

    <div class="kpis">
        <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="planilha" /></span>Linhas lidas</span><span class="val">{{ $imp->total_linhas }}</span><span class="foot">abaixo do cabeçalho</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="ok" /></span>{{ $imp->situacao === 'confirmada' ? 'Importadas' : 'Prontas para importar' }}</span><span class="val">{{ $imp->situacao === 'confirmada' ? $imp->importadas : $imp->validas }}</span><span class="foot">{{ reais(collect($imp->linhas)->where('situacao', 'ok')->sum(fn ($l) => (float) $l['dados']['quantidade'] * (float) $l['dados']['valor_unitario_estimado'])) }}</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-warn"><x-icone nome="alerta" /></span>Com erro</span><span class="val">{{ $imp->com_erro }}</span><span class="foot">ficam de fora</span></div>
        <div class="card kpi"><span class="lbl"><span class="chip c-warn"><x-icone nome="alerta" /></span>Duplicadas</span><span class="val">{{ $imp->duplicadas }}</span><span class="foot">já no plano ou repetidas</span></div>
    </div>

    <div class="card card-flush">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead><tr><th>Linha</th><th>Situação</th><th>Descrição</th><th>Categoria</th><th>Unidade req.</th><th class="num">Qtd.</th><th class="num">Valor unitário</th><th class="num">Total</th></tr></thead>
                <tbody>
                    @foreach ($imp->linhas as $l)
                        @php($d = $l['dados'])
                        <tr>
                            <td class="sigla">{{ $l['linha'] }}</td>
                            <td><span class="b {{ $rotulo[$l['situacao']][1] }}">{{ $rotulo[$l['situacao']][0] }}</span>
                                @foreach ($l['motivos'] as $m)<div class="small" style="color:#9F1D1D;margin-top:4px">{{ $m }}</div>@endforeach
                            </td>
                            <td>{{ $d['descricao'] ?? '—' }}<div class="muted small">{{ ($d['material_servico'] ?? '') === 'S' ? 'Serviço' : 'Material' }}@if (! empty($d['codigo_catalogo'])) · {{ $d['codigo_catalogo'] }}@endif @if (! empty($d['data_desejada'])) · para {{ \Illuminate\Support\Carbon::parse($d['data_desejada'])->format('m/Y') }}@endif</div></td>
                            <td class="muted">{{ $cat[$d['categoria'] ?? 0] ?? '—' }}</td>
                            <td>{{ $unidades[$d['unidade_id'] ?? 0] ?? '—' }}</td>
                            <td class="num">{{ is_numeric($d['quantidade'] ?? null) ? numero_br($d['quantidade'], 0) : ($d['quantidade'] ?? '—') }} {{ $d['unidade_fornecimento'] ?? '' }}</td>
                            <td class="num">{{ is_numeric($d['valor_unitario_estimado'] ?? null) ? reais($d['valor_unitario_estimado']) : ($d['valor_unitario_estimado'] ?? '—') }}</td>
                            <td class="num valor">{{ is_numeric($d['quantidade'] ?? null) && is_numeric($d['valor_unitario_estimado'] ?? null) ? reais((float) $d['quantidade'] * (float) $d['valor_unitario_estimado']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
