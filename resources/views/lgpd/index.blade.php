<x-layouts.app titulo="LGPD">
    <div class="row">
        <div><h1 class="h1">LGPD</h1><p class="sub">Pedidos dos titulares e registro das operações de tratamento. <span class="lei">Lei 13.709/2018, arts. 18, 19, 37 e 41</span></p></div>
        <a href="{{ route('lgpd.publico') }}" class="btn btn-s" target="_blank" rel="noopener"><x-icone nome="olho" />Ver canal público do titular</a>
    </div>
    <section class="card card-flush">
        <div style="padding:20px 24px 0"><h2>Pedidos dos titulares</h2><p class="hint">Prazo de resposta: 15 dias (art. 19, II).</p></div>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>Protocolo</th><th>Titular</th><th>Pedido</th><th>Prazo</th><th>Situação</th></tr></thead>
            <tbody>
                @forelse ($solicitacoes as $s)
                    <tr>
                        <td><a class="sigla" href="{{ route('lgpd.show', $s) }}">{{ $s->protocolo }}</a></td>
                        <td>{{ $s->nome }}<div class="muted small">{{ $s->email }}</div></td>
                        <td class="small">{{ \App\Models\SolicitacaoTitular::TIPOS[$s->tipo] ?? $s->tipo }}</td>
                        <td>{{ $s->prazo_em->format('d/m/Y') }}@if ($s->atrasada())<div class="small" style="color:#B42318;font-weight:600">atrasado</div>@endif</td>
                        <td><span class="b {{ in_array($s->situacao, ['concluida', 'indeferida']) ? 'b-mute' : ($s->atrasada() ? 'b-err' : 'b-info') }}">{{ \App\Models\SolicitacaoTitular::SITUACOES[$s->situacao] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted" style="padding:16px 24px">Nenhum pedido.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>
    <div class="paginacao">{{ $solicitacoes->links() }}</div>

    <div class="g2">
        <section class="card">
            <h2>Registro de tratamento</h2>
            <p class="hint">O que o sistema faz com dados pessoais, para quê e com que base legal (art. 37).</p>
            <div class="list">
                @forelse ($tratamentos as $t)
                    <div class="li" style="align-items:flex-start"><span class="chip c-teal"><x-icone nome="privacidade" /></span><div><b>{{ $t->nome }}</b><div class="meta">{{ \App\Models\OperacaoTratamento::BASES_LEGAIS[$t->base_legal] ?? $t->base_legal }}</div><div class="small" style="margin-top:4px">{{ $t->finalidade }}</div><div class="meta">Dados: {{ $t->dados_tratados }} · Retenção: {{ $t->retencao }}</div></div></div>
                @empty
                    <div class="li muted">Nenhuma operação registrada.</div>
                @endforelse
            </div>
        </section>
        <section class="card">
            <h2>Incluir operação</h2>
            <form method="POST" action="{{ route('lgpd.tratamentos.store') }}" class="form-grid" style="margin-top:14px" novalidate>
                @csrf
                <x-campo nome="nome" rotulo="Operação" obrigatorio classe="full" />
                <x-campo nome="finalidade" rotulo="Finalidade" obrigatorio classe="full" />
                <x-campo nome="base_legal" rotulo="Base legal" obrigatorio classe="full"><select id="campo-base_legal" name="base_legal" class="input">@foreach (\App\Models\OperacaoTratamento::BASES_LEGAIS as $v => $r)<option value="{{ $v }}">{{ $r }}</option>@endforeach</select></x-campo>
                <x-campo nome="dados_tratados" rotulo="Dados tratados" obrigatorio />
                <x-campo nome="titulares" rotulo="Titulares" obrigatorio />
                <x-campo nome="compartilhamento" rotulo="Compartilhamento" />
                <x-campo nome="retencao" rotulo="Retenção" obrigatorio />
                <x-campo nome="medidas_seguranca" rotulo="Medidas de segurança" obrigatorio classe="full" />
                <div class="campo"><button class="btn btn-p" type="submit">Incluir</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
