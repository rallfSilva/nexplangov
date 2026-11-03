<x-layouts.app titulo="Impugnações e esclarecimentos">
    @php($conduz = auth()->user()->can('licitacao.conduzir'))
    <div>
        <a href="{{ route('processos.show', $processo) }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Processo {{ $processo->identificacao() }}</a>
        <h1 class="h1" style="margin-top:8px">Impugnações e esclarecimentos</h1>
        <p class="sub">Qualquer pessoa pode pedir até 3 dias úteis antes da abertura. A resposta sai em até 3 dias úteis, limitada ao último dia útil antes da abertura. <span class="lei">Art. 164</span>
            @if ($processo->situacao === 'publicado' || $processo->situacao === 'em_julgamento') · <a href="{{ route('edital.publico', $processo) }}" target="_blank" style="font-weight:600">Página pública do edital</a>@endif</p>
    </div>

    @foreach ($impugnacoes as $i)
        <section class="card">
            <div class="row"><div><b>{{ $i->protocolo }}</b> · {{ \App\Models\Impugnacao::TIPOS[$i->tipo] }} · {{ $i->autor_nome }}
                <div class="small muted">Recebida em {{ $i->recebida_em->format('d/m/Y H:i') }} ({{ $i->canal === 'publico' ? 'página pública' : 'registro interno' }}) · prazo de resposta {{ $i->prazo_resposta_em?->format('d/m/Y') }}</div></div>
                <div>@if (! $i->tempestiva)<span class="b b-warn">Fora do prazo</span> @endif
                    @if ($i->situacao === 'recebida')<span class="b {{ $i->atrasada() ? 'b-err' : 'b-info' }}">{{ $i->atrasada() ? 'Resposta atrasada' : 'A responder' }}</span>@elseif ($i->situacao === 'nao_conhecida')<span class="b b-mute">Não conhecida</span>@else<span class="b b-ok">{{ \App\Models\Impugnacao::RESULTADOS[$i->resultado] }}</span>@endif</div></div>
            <p style="white-space:pre-line">{{ $i->texto }}</p>
            @if ($i->resposta)<div class="aviso-lei"><b>Resposta</b> ({{ $i->respondida_em->format('d/m/Y') }}):<br><span style="white-space:pre-line">{{ $i->resposta }}</span></div>@endif
            @if ($conduz && $i->situacao === 'recebida')
                <form method="POST" action="{{ route('impugnacoes.responder', [$processo, $i]) }}" class="form-grid" style="margin-top:12px">@csrf
                    <div class="campo"><label>Decisão</label><select name="resultado" class="input">@foreach (\App\Models\Impugnacao::RESULTADOS as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach<option value="nao_conhecida">Não conhecida (intempestiva ou sem cabimento)</option></select></div>
                    <div class="campo full"><label>Resposta (será divulgada no sítio oficial)</label><textarea name="resposta" class="input" rows="4" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-p" type="submit">Responder</button></div></form>
            @endif
        </section>
    @endforeach
    @if ($impugnacoes->isEmpty())<div class="card vazio"><b>Nenhum pedido recebido.</b></div>@endif

    @if ($conduz)
        <section class="card"><h2>Registrar pedido recebido por outro canal</h2>
            <form method="POST" action="{{ route('impugnacoes.store', $processo) }}" class="form-grid">@csrf
                <div class="campo"><label>Tipo</label><select name="tipo" class="input">@foreach (\App\Models\Impugnacao::TIPOS as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach</select></div>
                <x-campo nome="autor_nome" rotulo="Nome de quem pediu" obrigatorio /><x-campo nome="autor_email" rotulo="E-mail" tipo="email" />
                <div class="campo full"><label>Texto do pedido</label><textarea name="texto" class="input" rows="4" required></textarea></div>
                <div class="form-acoes full"><button class="btn btn-s" type="submit">Registrar</button></div></form>
        </section>
    @endif
</x-layouts.app>
