<x-layouts.app titulo="Limite de dispensa">
    <div>
        <h1 class="h1">Limite de dispensa por valor</h1>
        <p class="sub">Antes de abrir uma dispensa, confira quanto o órgão já contratou no ano para objetos da mesma natureza. <span class="lei">Art. 75, I e II, e § 1º</span></p>
    </div>
    <form method="GET" class="card filtros">
        @if ($orgaos->isNotEmpty())
            <div class="campo"><label for="f-orgao">Órgão</label><select id="f-orgao" name="orgao" class="input">@foreach ($orgaos as $o)<option value="{{ $o->id }}" @selected($orgao?->id === $o->id)>{{ $o->sigla }}</option>@endforeach</select></div>
        @endif
        <div class="campo"><label for="f-ano">Exercício</label><input id="f-ano" name="ano" type="number" value="{{ $ano }}" class="input"></div>
        <div class="campo"><label for="f-inc">Tipo de objeto</label><select id="f-inc" name="inciso" class="input"><option value="II" @selected($inciso === 'II')>Compras e outros serviços (inciso II)</option><option value="I" @selected($inciso === 'I')>Obras e serviços de engenharia (inciso I)</option></select></div>
        <div class="campo"><label for="f-nat">Natureza do objeto</label><input id="f-nat" name="natureza" value="{{ $natureza }}" class="input" list="naturezas" placeholder="Ex.: 3.3.90.30.16"><datalist id="naturezas">@foreach ($naturezas as $n)<option value="{{ $n }}">@endforeach</datalist></div>
        <div class="campo"><label for="f-val">Valor que pretende contratar (R$)</label><input id="f-val" name="valor" value="{{ $valor ? numero_br($valor) : '' }}" class="input" inputmode="decimal" required></div>
        <div class="btns"><button class="btn btn-p" type="submit">Verificar</button></div>
    </form>
    @if ($erro)<div class="flash flash-erro"><x-icone nome="alerta" style="flex:none" /><div>{{ $erro }}</div></div>@endif
    @if ($resultado)
        @php($uso = $resultado['limite'] > 0 ? min(100, $resultado['total'] / $resultado['limite'] * 100) : 100)
        <section class="card">
            <div class="row"><h2>{{ $resultado['permitido'] ? 'Cabe na dispensa' : 'Não cabe na dispensa: é preciso licitar' }}</h2><span class="b {{ $resultado['permitido'] ? 'b-ok' : 'b-err' }}">{{ numero_br($uso, 1) }}% do limite</span></div>
            <p class="hint">{{ $resultado['dispositivo'] }} em {{ $ano }}: {{ reais($resultado['limite']) }}@if ($orgao?->limite_dispensa_em_dobro) (em dobro, art. 75, § 2º)@endif · {{ $resultado['ato'] }}@unless ($resultado['conferido']) <span class="b b-warn">valor a conferir no DOU</span>@endunless</p>
            <div class="barra {{ ! $resultado['permitido'] ? 'estourou' : ($uso > 80 ? 'alerta' : '') }}" style="margin:18px 0"><span style="width:{{ round($uso, 1) }}%"></span></div>
            <dl class="dados">
                <div><dt>Já contratado</dt><dd class="valor">{{ reais($resultado['ja_contratado']) }}</dd></div>
                <div><dt>Este valor</dt><dd class="valor">{{ reais($valor) }}</dd></div>
                <div><dt>Total</dt><dd class="valor">{{ reais($resultado['total']) }}</dd></div>
                <div><dt>Saldo disponível</dt><dd class="valor">{{ reais($resultado['disponivel']) }}</dd></div>
            </dl>
            @if ($resultado['processos'])
                <p class="small muted" style="margin-top:14px">Dispensas consideradas: @foreach ($resultado['processos'] as $p){{ $p['numero'] }} ({{ reais($p['valor']) }})@if (! $loop->last), @endif @endforeach</p>
            @endif
        </section>
    @endif
</x-layouts.app>
