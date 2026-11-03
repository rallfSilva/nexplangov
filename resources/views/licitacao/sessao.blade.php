<x-layouts.app titulo="Sessão de julgamento">
    @php($conduz = auth()->user()->can('licitacao.conduzir'))
    @php($emJulgamento = $processo->situacao === 'em_julgamento')
    @php($recebe = in_array($processo->situacao, ['publicado', 'em_julgamento'], true))
    <div>
        <a href="{{ route('processos.show', [$processo, 'aba' => 'resultado']) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processo {{ $processo->identificacao() }}</a>
        <h1 class="h1" style="margin-top:8px">Sessão de julgamento</h1>
        <p class="sub">Critério: <b>{{ $criterio->rotulo() }}</b> · disputa: {{ \App\Licitacao\Enums\ModoDisputa::from($processo->modo_disputa)->rotulo() }} <span class="lei">art. 33 e art. 56</span> · <a href="{{ route('impugnacoes.index', $processo) }}" style="font-weight:600">Impugnações e esclarecimentos</a></p>
    </div>

    @unless ($emJulgamento)
        <div class="aviso-lei">Propostas podem ser registradas com o processo publicado. Lances, habilitação e adjudicação só com o processo <b>em julgamento</b> (abra a sessão na tramitação).</div>
    @endunless

    @forelse ($processo->itens as $item)
        @php($c = $classificacoes[$item->id])
        <section class="card">
            <div class="row">
                <div><h2 style="margin:0">Item {{ $item->numero }}: {{ \Illuminate\Support\Str::limit($item->descricao, 90) }}</h2>
                    <p class="hint">{{ number_format((float) $item->quantidade, 2, ',', '.') }} {{ $item->unidade_medida }} · estimado {{ reais($item->valor_unitario_estimado, 4) }}@if ($item->adjudicado_em) · <span class="b b-ok">Adjudicado</span>@endif</p></div>
            </div>

            @if ($c['empatadas'])<div class="aviso-lei" style="margin:8px 0">Propostas <b>empatadas</b> na primeira posição. Registre o critério de desempate da vencedora <span class="lei">art. 60</span>.</div>@endif
            @if ($c['empate_ficto'])<div class="aviso-lei" style="margin:8px 0">ME/EPP em <b>empate ficto</b> com a melhor proposta: convoque-as a cobrir o valor registrando um lance <span class="lei">art. 4º</span>.</div>@endif

            <div class="tbl-wrap"><table class="tbl">
                <thead><tr><th>#</th><th>Fornecedor</th><th class="num">Proposta</th><th class="num">Vigente</th><th>Situação</th><th>Habilitação</th><th></th></tr></thead>
                <tbody>
                    @php($pos = 0)
                    @foreach ($item->propostas->sortBy(fn ($p) => array_search($p->id, array_map(fn ($o) => $o->id, $c['ordem'])) === false ? 999 : array_search($p->id, array_map(fn ($o) => $o->id, $c['ordem']))) as $p)
                        @php($ativa = in_array($p->situacao, ['classificada', 'vencedora']))
                        <tr>
                            <td>{{ $ativa ? ++$pos : '—' }}</td>
                            <td><b>{{ $p->fornecedor->razao_social }}</b>@if ($p->me_epp) <span class="b b-info">ME/EPP</span>@endif @if ($p->marca_modelo)<div class="small muted">{{ $p->marca_modelo }}</div>@endif</td>
                            <td class="num valor">{{ number_format((float) $p->valor, 4, ',', '.') }}</td>
                            <td class="num valor">{{ number_format($p->valorVigente(), 4, ',', '.') }}@if ($p->lances->count())<div class="small muted">{{ $p->lances->count() }} lance(s)</div>@endif</td>
                            <td>@switch($p->situacao)@case('vencedora')<span class="b b-ok">Vencedora</span>@break @case('classificada')<span class="b b-info">Classificada</span>@break @default<span class="b b-err">{{ $p->situacao === 'recusada' ? 'Recusada' : 'Desclassificada' }}</span>@endswitch
                                @if ($p->motivo)<div class="small muted">{{ $p->motivo }}</div>@endif
                                @if ($p->desempate_criterio)<div class="small muted">Desempate: {{ $desempates[$p->desempate_criterio] }}</div>@endif</td>
                            <td>{{ ['pendente' => 'Pendente', 'habilitado' => 'Habilitado', 'inabilitado' => 'Inabilitado'][$p->habilitacao] }}</td>
                            <td class="acoes">
                                @if ($conduz && $emJulgamento && ! $item->adjudicado_em && $p->situacao === 'classificada')
                                    <details><summary class="btn btn-s btn-sm" style="list-style:none">Ações</summary>
                                        <div style="display:grid;gap:8px;margin-top:8px;min-width:260px">
                                            @if ($disputaAberta)
                                                <form method="POST" action="{{ route('licitacao.lance', [$processo, $p]) }}" class="btns">@csrf
                                                    <input name="valor" class="input" style="width:110px" inputmode="decimal" placeholder="Lance" aria-label="Valor do lance" required>
                                                    <input name="intervalo" class="input" style="width:90px" inputmode="decimal" placeholder="Interv." aria-label="Intervalo mínimo">
                                                    <button class="btn btn-s btn-sm" type="submit">Lance</button></form>
                                            @endif
                                            @if ($p->habilitacao !== 'habilitado')<form method="POST" action="{{ route('licitacao.habilitar', [$processo, $p]) }}">@csrf<button class="btn btn-s btn-sm" type="submit">Habilitar</button></form>@endif
                                            <form method="POST" action="{{ route('licitacao.inabilitar', [$processo, $p]) }}" class="btns">@csrf<input name="motivo" class="input" placeholder="Motivo da inabilitação" required minlength="10"><button class="btn btn-s btn-sm" type="submit">Inabilitar</button></form>
                                            <form method="POST" action="{{ route('licitacao.desclassificar', [$processo, $p]) }}" class="btns">@csrf<input name="motivo" class="input" placeholder="Motivo da desclassificação" required minlength="10"><button class="btn btn-s btn-sm" type="submit">Desclassificar</button></form>
                                            @if (in_array($p->id, $c['empatadas']))
                                                <form method="POST" action="{{ route('licitacao.desempate', [$processo, $p]) }}" class="btns">@csrf
                                                    <select name="criterio" class="input" aria-label="Critério de desempate">@foreach ($desempates as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach</select>
                                                    <input name="justificativa" class="input" placeholder="Como foi aplicado" required><button class="btn btn-s btn-sm" type="submit">Desempatar</button></form>
                                            @endif
                                            <form method="POST" action="{{ route('licitacao.adjudicar', [$processo, $item]) }}" class="btns">@csrf<input type="hidden" name="proposta_id" value="{{ $p->id }}">
                                                @if ($c['empate_ficto'])<input name="empate_ficto" class="input" placeholder="Como tratou o empate ficto">@endif
                                                <button class="btn btn-p btn-sm" type="submit">Adjudicar</button></form>
                                        </div></details>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if ($item->propostas->isEmpty())<tr><td colspan="7" class="vazio">Nenhuma proposta registrada.</td></tr>@endif
                </tbody>
            </table></div>

            @if ($conduz && $recebe && ! $item->adjudicado_em)
                <form method="POST" action="{{ route('licitacao.proposta', [$processo, $item]) }}" class="btns" style="margin-top:12px">@csrf
                    <select name="fornecedor_id" class="input" style="width:auto;min-width:240px" aria-label="Fornecedor" required><option value="">Fornecedor</option>@foreach ($fornecedores as $f)<option value="{{ $f->id }}">{{ $f->razao_social }}</option>@endforeach</select>
                    <input name="valor" class="input" style="width:140px" inputmode="decimal" placeholder="{{ $criterio === \App\Licitacao\Enums\CriterioJulgamento::MaiorDesconto ? 'Desconto (%)' : 'Valor unitário' }}" aria-label="Valor da proposta" required>
                    <input name="marca_modelo" class="input" style="width:180px" placeholder="Marca ou modelo" aria-label="Marca ou modelo">
                    <button class="btn btn-s btn-sm" type="submit"><x-icone nome="mais" tamanho="16" />Registrar proposta</button></form>
            @endif
            @if ($conduz && $emJulgamento && $item->adjudicado_em)
                <form method="POST" action="{{ route('licitacao.revogar', [$processo, $item]) }}" class="btns" style="margin-top:12px">@csrf<input name="motivo" class="input" style="width:300px" placeholder="Motivo para revogar a adjudicação" required minlength="10"><button class="btn btn-s btn-sm" type="submit">Revogar adjudicação</button></form>
            @endif
        </section>
    @empty
        <div class="card vazio"><b>O processo não tem itens.</b></div>
    @endforelse
</x-layouts.app>
