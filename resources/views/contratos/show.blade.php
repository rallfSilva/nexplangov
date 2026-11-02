<x-layouts.app :titulo="'Contrato '.$contrato->identificacao()">
    <div class="row">
        <div style="min-width:0">
            <a href="{{ route('contratos.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Contratos</a>
            <h1 class="h1" style="margin-top:8px">Contrato {{ $contrato->identificacao() }} <span class="b {{ $contrato->situacao === 'vigente' ? 'b-ok' : 'b-mute' }}" style="vertical-align:middle">{{ \App\Models\Contrato::SITUACOES[$contrato->situacao] }}</span></h1>
            <p class="sub">{{ $contrato->fornecedor->razao_social }} · {{ $contrato->fornecedor->documento }}</p>
        </div>
        @can('pncp.enviar')
            <form method="POST" action="{{ route('pncp.contrato', $contrato) }}">@csrf<button class="btn btn-p" type="submit"><x-icone nome="pncp" />{{ $contrato->numero_controle_pncp ? 'Retificar no PNCP' : 'Publicar no PNCP' }}</button></form>
        @endcan
    </div>

    <div class="kpis">
        <div class="card kpi"><span class="lbl">Valor global</span><span class="val valor">{{ reais($contrato->valor_global) }}</span><span class="foot">inicial {{ reais($contrato->valor_inicial) }}</span></div>
        <div class="card kpi"><span class="lbl">Vigência</span><span class="val" style="font-size:24px">{{ $contrato->vigencia_fim->format('d/m/Y') }}</span><span class="foot">{{ $dias < 0 ? 'vencido há '.abs($dias).' dias' : 'faltam '.$dias.' dias' }}</span></div>
        <div class="card kpi"><span class="lbl">Acréscimos (limite {{ $contrato->reforma_equipamento ? '50' : '25' }}%)</span><span class="val">{{ numero_br($limites['percentual_acrescimo']) }}%</span><div class="barra {{ $limites['percentual_acrescimo'] > ($contrato->reforma_equipamento ? 40 : 20) ? 'alerta' : '' }}"><span style="width:{{ min(100, $limites['percentual_acrescimo'] / ($contrato->reforma_equipamento ? 50 : 25) * 100) }}%"></span></div></div>
        <div class="card kpi"><span class="lbl">Supressões (limite 25%)</span><span class="val">{{ numero_br($limites['percentual_supressao']) }}%</span><div class="barra"><span style="width:{{ min(100, $limites['percentual_supressao'] / 25 * 100) }}%"></span></div></div>
    </div>

    <div class="g2">
        <section class="card">
            <h2>Dados</h2>
            <p style="margin:10px 0 0;line-height:1.6">{{ $contrato->objeto }}</p>
            <dl class="dados">
                <div><dt>Gestor</dt><dd>{{ $contrato->gestor?->name ?? '—' }}</dd></div>
                <div><dt>Fiscal</dt><dd>{{ $contrato->fiscal?->name ?? '—' }}</dd></div>
                <div><dt>Assinatura</dt><dd>{{ $contrato->assinatura->format('d/m/Y') }}</dd></div>
                <div><dt>Início</dt><dd>{{ $contrato->vigencia_inicio->format('d/m/Y') }}</dd></div>
                <div><dt>Contínuo</dt><dd>{{ $contrato->servico_continuo ? 'Sim (até 10 anos)' : 'Não' }}</dd></div>
                <div><dt>Garantia</dt><dd>{{ $contrato->garantia_percentual ? numero_br($contrato->garantia_percentual).'%' : '—' }}</dd></div>
                <div><dt>Base para o art. 125</dt><dd class="valor">{{ reais($base) }}</dd></div>
                @if ($contrato->processo)<div><dt>Processo</dt><dd><a href="{{ route('processos.show', $contrato->processo) }}">{{ $contrato->processo->identificacao() }}</a></dd></div>@endif
                <div><dt>PNCP</dt><dd>{{ $contrato->numero_controle_pncp ?? 'Não publicado' }}</dd></div>
            </dl>
        </section>
        @can('contratos.gerenciar')
            @if ($contrato->situacao === 'vigente')
                <section class="card">
                    <h2>Novo termo</h2>
                    <p class="hint">O sistema bloqueia acréscimos acima do limite e prorrogações acima de 10 anos.</p>
                    <form method="POST" action="{{ route('contratos.termos.store', $contrato) }}" class="form-grid" style="margin-top:14px" novalidate>
                        @csrf
                        <x-campo nome="tipo" rotulo="Tipo" obrigatorio classe="full"><select id="campo-tipo" name="tipo" class="input">@foreach (\App\Licitacao\Enums\TipoTermo::opcoes() as $v => $r)<option value="{{ $v }}" @selected(old('tipo') === $v)>{{ $r }}</option>@endforeach</select></x-campo>
                        <x-campo nome="numero" rotulo="Número" obrigatorio />
                        <x-campo nome="assinatura" rotulo="Assinatura" tipo="date" obrigatorio />
                        <x-campo nome="valor_acrescimo" rotulo="Acréscimo (R$)" inputmode="decimal" />
                        <x-campo nome="valor_supressao" rotulo="Supressão (R$)" inputmode="decimal" />
                        <x-campo nome="valor_reajuste" rotulo="Reajuste apostilado (R$)" inputmode="decimal" />
                        <x-campo nome="nova_vigencia_fim" rotulo="Nova vigência até" tipo="date" />
                        <x-campo nome="justificativa" rotulo="Justificativa" obrigatorio classe="full"><textarea id="campo-justificativa" name="justificativa" class="input" rows="3">{{ old('justificativa') }}</textarea></x-campo>
                        <div class="campo"><button class="btn btn-p" type="submit">Registrar termo</button></div>
                    </form>
                </section>
            @endif
        @endcan
    </div>

    <section class="card card-flush">
        <div style="padding:20px 24px 0"><h2>Termos e apostilamentos</h2></div>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>Termo</th><th>Tipo</th><th>Assinatura</th><th class="num">Acréscimo</th><th class="num">Supressão</th><th class="num">Reajuste</th><th>Nova vigência</th><th></th></tr></thead>
            <tbody>
                @forelse ($contrato->termos as $t)
                    <tr>
                        <td class="sigla">{{ $t->numero }}</td>
                        <td class="small">{{ $t->tipoEnum()->rotulo() }}</td>
                        <td>{{ $t->assinatura->format('d/m/Y') }}</td>
                        <td class="num valor">{{ reais($t->valor_acrescimo) }}</td>
                        <td class="num valor">{{ reais($t->valor_supressao) }}</td>
                        <td class="num valor">{{ reais($t->valor_reajuste) }}</td>
                        <td>{{ $t->nova_vigencia_fim?->format('d/m/Y') ?? '—' }}</td>
                        <td class="acoes">@can('pncp.enviar')<form method="POST" action="{{ route('pncp.termo', $t) }}">@csrf<button class="btn btn-s btn-sm" type="submit">PNCP</button></form>@endcan</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted" style="padding:16px 24px">Nenhum termo.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>

    <section class="card"><h2>Publicações no PNCP</h2>@include('pncp._lista', ['envios' => $contrato->enviosPncp])</section>
</x-layouts.app>
