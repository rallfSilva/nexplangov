<x-layouts.app titulo="Valores da lei">
    <div><h1 class="h1">Valores da Lei 14.133</h1><p class="sub">Os valores da lei são atualizados todo ano por decreto (art. 182). Cadastre o decreto do ano e marque como conferido depois de checar no Diário Oficial da União.</p></div>
    <section class="card card-flush"><div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Ano</th><th>Dispositivo</th><th class="num">Valor</th><th>Ato normativo</th><th>Situação</th></tr></thead>
        <tbody>
            @foreach ($valores as $v)
                <tr><td class="sigla">{{ $v->ano }}</td><td>{{ \App\Models\ValorLimite::DISPOSITIVOS[$v->dispositivo] ?? $v->dispositivo }}</td><td class="num valor">{{ reais($v->valor) }}</td><td class="small">{{ $v->ato_normativo }}</td><td>@if ($v->conferido)<span class="b b-ok">Conferido</span>@else<span class="b b-warn">A conferir</span>@endif</td></tr>
            @endforeach
        </tbody>
    </table></div></section>
    <section class="card">
        <h2>Cadastrar ou corrigir valor</h2>
        <form method="POST" action="{{ route('configuracoes.valores') }}" class="form-grid" style="margin-top:14px" novalidate>
            @csrf
            <x-campo nome="ano" rotulo="Ano" tipo="number" :valor="now()->year" obrigatorio />
            <x-campo nome="dispositivo" rotulo="Dispositivo" obrigatorio><select id="campo-dispositivo" name="dispositivo" class="input">@foreach (\App\Models\ValorLimite::DISPOSITIVOS as $k => $r)<option value="{{ $k }}">{{ $r }}</option>@endforeach</select></x-campo>
            <x-campo nome="valor" rotulo="Valor (R$)" obrigatorio inputmode="decimal" />
            <x-campo nome="ato_normativo" rotulo="Decreto" obrigatorio placeholder="Decreto nº ..." />
            <div class="campo"><label class="check"><input type="checkbox" name="conferido" value="1"> Conferido no DOU</label></div>
            <div class="campo"><button class="btn btn-p" type="submit">Salvar</button></div>
        </form>
    </section>
</x-layouts.app>
