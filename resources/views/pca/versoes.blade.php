@php
$cat = \App\Licitacao\Enums\CategoriaProcesso::opcoes();
$rotuloVersao = fn ($v) => $v === 'atual' ? ($plano->emRevisao() ? 'Revisão em andamento' : 'Itens atuais') : 'Versão '.$v;
$fmt = function ($campo, $v) use ($cat, $unidades) {
    if ($v === null || $v === '') return '—';
    return match ($campo) {
        'quantidade' => numero_br($v, 0),
        'valor_unitario_estimado', 'valor_total_estimado' => reais($v),
        'categoria' => $cat[(int) $v] ?? $v,
        'prioridade' => \App\Models\ItemPca::PRIORIDADES[(int) $v] ?? $v,
        'material_servico' => $v === 'S' ? 'Serviço' : 'Material',
        'unidade_id' => $unidades[(int) $v] ?? '—',
        'data_desejada' => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y'),
        default => $v,
    };
};
@endphp
<x-layouts.app :titulo="'Versões do PCA '.$plano->ano">
    <div class="row">
        <div>
            <a href="{{ route('pca.show', $plano) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />PCA {{ $plano->ano }}</a>
            <h1 class="h1" style="margin-top:8px">Versões e alterações do PCA {{ $plano->ano }}</h1>
            <p class="sub">Cada aprovação guarda uma cópia dos itens. Depois de aprovado, o plano só muda por revisão justificada e aprovada de novo. <span class="lei">art. 12, VII e § 1º</span></p>
        </div>
    </div>

    <section class="card">
        <h2>Versões aprovadas</h2>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>Versão</th><th>Aprovada em</th><th>Por</th><th class="num">Itens</th><th class="num">Valor total</th><th>Justificativa da revisão</th></tr></thead>
            <tbody>
                @forelse ($versoes as $v)
                    <tr>
                        <td class="sigla">{{ $v->numero }}@if ($v->numero === $plano->versao_atual) <span class="b b-ok">vigente</span>@endif</td>
                        <td>{{ $v->aprovado_em?->format('d/m/Y H:i') }}</td>
                        <td>{{ $v->aprovador?->name ?? '—' }}</td>
                        <td class="num">{{ $v->qtd_itens }}</td>
                        <td class="num valor">{{ reais($v->valor_total) }}</td>
                        <td>{{ $v->justificativa ?? ($v->numero === 1 ? 'Versão inicial' : '—') }}@if ($v->autorRevisao)<div class="muted small">revisão aberta por {{ $v->autorRevisao->name }}</div>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="vazio"><b>Nenhuma versão aprovada ainda</b>A versão 1 é criada na primeira aprovação.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>

    @if ($diff)
        <section class="card">
            <form method="GET" action="{{ route('pca.versoes', $plano) }}" class="row" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
                <h2 style="margin:0;flex:1">O que mudou</h2>
                <div class="campo"><label for="cmp-de">De</label><select id="cmp-de" name="de" class="input">
                    <option value="0" @selected($de === 0)>Plano vazio</option>
                    @foreach ($versoes as $v)<option value="{{ $v->numero }}" @selected($de === $v->numero)>Versão {{ $v->numero }}</option>@endforeach
                </select></div>
                <div class="campo"><label for="cmp-para">Para</label><select id="cmp-para" name="para" class="input">
                    @foreach ($opcoes as $o)<option value="{{ $o }}" @selected((string) $para === (string) $o)>{{ $rotuloVersao($o) }}</option>@endforeach
                </select></div>
                <button class="btn btn-s" type="submit">Comparar</button>
            </form>

            <div class="kpis" style="margin-top:14px">
                <div class="card kpi"><span class="lbl"><span class="chip c-teal"><x-icone nome="mais" /></span>Incluídos</span><span class="val">{{ count($diff['incluidos']) }}</span></div>
                <div class="card kpi"><span class="lbl"><span class="chip c-warn"><x-icone nome="lixeira" /></span>Excluídos</span><span class="val">{{ count($diff['excluidos']) }}</span></div>
                <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="editar" /></span>Alterados</span><span class="val">{{ count($diff['alterados']) }}</span></div>
                <div class="card kpi"><span class="lbl"><span class="chip c-navy"><x-icone nome="cifrao" /></span>Valor total</span><span class="val valor">{{ reais($diff['valor_depois']) }}</span><span class="foot">antes {{ reais($diff['valor_antes']) }} ({{ $diff['valor_depois'] >= $diff['valor_antes'] ? '+' : '−' }}{{ reais(abs($diff['valor_depois'] - $diff['valor_antes'])) }})</span></div>
            </div>

            <div class="tbl-wrap" style="margin-top:14px"><table class="tbl">
                <thead><tr><th>Item</th><th>Mudança</th><th>Descrição</th><th>Detalhe</th></tr></thead>
                <tbody>
                    @foreach ($diff['incluidos'] as $i)
                        <tr><td class="sigla">{{ $i['numero'] }}</td><td><span class="b b-ok">Incluído</span></td><td>{{ $i['descricao'] }}</td><td>{{ $fmt('quantidade', $i['quantidade']) }} {{ $i['unidade_fornecimento'] }} · {{ $fmt('valor_total_estimado', $i['valor_total_estimado']) }}</td></tr>
                    @endforeach
                    @foreach ($diff['excluidos'] as $i)
                        <tr><td class="sigla">{{ $i['numero'] }}</td><td><span class="b b-err">Excluído</span></td><td>{{ $i['descricao'] }}</td><td>{{ $fmt('quantidade', $i['quantidade']) }} {{ $i['unidade_fornecimento'] }} · {{ $fmt('valor_total_estimado', $i['valor_total_estimado']) }}</td></tr>
                    @endforeach
                    @foreach ($diff['alterados'] as $a)
                        <tr><td class="sigla">{{ $a['numero'] }}</td><td><span class="b b-warn">Alterado</span></td><td>{{ $a['descricao'] }}</td>
                            <td>@foreach ($a['mudancas'] as $campo => $m)<div class="small"><b>{{ \App\Licitacao\Regras\VersoesPca::CAMPOS[$campo] }}:</b> {{ $fmt($campo, $m['de']) }} → {{ $fmt($campo, $m['para']) }}</div>@endforeach</td></tr>
                    @endforeach
                    @if (! $diff['incluidos'] && ! $diff['excluidos'] && ! $diff['alterados'])
                        <tr><td colspan="4" class="vazio"><b>Sem diferenças</b>Os itens são iguais nas duas versões.</td></tr>
                    @endif
                </tbody>
            </table></div>
        </section>
    @endif
</x-layouts.app>
