<x-layouts.auth titulo="Edital {{ $processo->identificacao() }}">
    <h1 class="h1">Edital {{ $processo->identificacao() }}</h1>
    <p class="sub">{{ $processo->orgao->nome }}</p>
    <x-flash />
    <dl class="dl">
        <div><dt>Objeto</dt><dd>{{ $processo->objeto }}</dd></div>
        <div><dt>Modalidade</dt><dd>{{ $processo->modalidadeEnum()->rotulo() }}</dd></div>
        @if ($processo->abertura_propostas_em)<div><dt>Abertura das propostas</dt><dd>{{ $processo->abertura_propostas_em->format('d/m/Y H:i') }}</dd></div>@endif
    </dl>

    <h2 style="margin-top:18px">Respostas divulgadas</h2>
    @forelse ($respostas as $r)
        <div class="aviso-lei" style="margin:8px 0"><b>{{ \App\Models\Impugnacao::TIPOS[$r->tipo] }} {{ $r->protocolo }}</b><br><span style="white-space:pre-line">{{ $r->texto }}</span><hr style="border:0;border-top:1px solid #CBEAEC;margin:8px 0"><b>Resposta:</b> <span style="white-space:pre-line">{{ $r->resposta }}</span></div>
    @empty
        <p class="muted small">Nenhuma resposta divulgada até agora.</p>
    @endforelse

    @if ($processo->ata_sessao_caminho)
        <p style="margin-top:14px"><a href="{{ route('edital.ata', $processo) }}" style="font-weight:600">Ata da sessão pública (PDF)</a> <span class="muted small">lavrada em {{ $processo->sessao_encerrada_em?->format('d/m/Y H:i') }}</span></p>
    @endif

    @if ($recursos->isNotEmpty())
        <h2 style="margin-top:18px">Recursos <span class="lei">arts. 165 a 168</span></h2>
        @foreach ($recursos as $r)
            <div class="aviso-lei" style="margin:8px 0">
                <b>{{ $r->fornecedor->razao_social }}</b> · {{ \App\Models\Recurso::TIPOS[$r->tipo] }}@if ($r->item) · item {{ $r->item->numero }}@endif
                · <b>{{ \App\Models\Recurso::SITUACOES[$r->situacaoAtual()] }}</b>
                @if ($r->prazo_razoes && $r->situacao === 'intencao')<br>Razões até {{ $r->prazo_razoes->format('d/m/Y') }}.@endif
                @if ($r->prazo_contrarrazoes && $r->situacao === 'razoes')<br>Contrarrazões até {{ $r->prazo_contrarrazoes->format('d/m/Y') }}.@endif
                @if ($r->razoes)<hr style="border:0;border-top:1px solid #CBEAEC;margin:8px 0"><b>Razões:</b> <span style="white-space:pre-line">{{ $r->razoes }}</span>@endif
                @foreach ($r->contrarrazoes as $c)<hr style="border:0;border-top:1px solid #CBEAEC;margin:8px 0"><b>Contrarrazões de {{ $c->fornecedor->razao_social }}:</b> <span style="white-space:pre-line">{{ $c->texto }}</span>@endforeach
                @if ($r->reconsideracao_texto)<hr style="border:0;border-top:1px solid #CBEAEC;margin:8px 0"><b>{{ $r->reconsideracao === 'reconsiderou' ? 'Reconsideração do agente' : 'Agente manteve o ato' }}:</b> <span style="white-space:pre-line">{{ $r->reconsideracao_texto }}</span>@endif
                @if ($r->decisao)<hr style="border:0;border-top:1px solid #CBEAEC;margin:8px 0"><b>Decisão da autoridade ({{ \App\Models\Recurso::RESULTADOS[$r->resultado] }}):</b> <span style="white-space:pre-line">{{ $r->decisao }}</span>@endif
            </div>
        @endforeach
    @endif

    @if ($aceita)
        <h2 style="margin-top:18px">Pedir esclarecimento ou impugnar o edital <span class="lei">art. 164</span></h2>
        <p class="small muted">O pedido vale até 3 dias úteis antes da abertura. Seu nome e e-mail são usados só para responder (LGPD).</p>
        <form method="POST" action="{{ route('edital.pedido', $processo) }}" class="form-grid">@csrf
            <div class="campo"><label for="tipo">Tipo</label><select id="tipo" name="tipo" class="input">@foreach (\App\Models\Impugnacao::TIPOS as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach</select></div>
            <x-campo nome="autor_nome" rotulo="Nome" obrigatorio /><x-campo nome="autor_email" rotulo="E-mail" tipo="email" obrigatorio />
            <div class="campo full"><label for="texto">Seu pedido</label><textarea id="texto" name="texto" class="input" rows="5" required>{{ old('texto') }}</textarea></div>
            <div style="position:absolute;left:-9999px" aria-hidden="true"><label>Não preencha<input name="site" tabindex="-1" autocomplete="off"></label></div>
            <div class="form-acoes full"><button class="btn btn-p" type="submit">Enviar pedido</button></div></form>
    @endif
</x-layouts.auth>
