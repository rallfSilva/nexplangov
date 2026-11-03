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
                        <x-campo nome="tipo" rotulo="Tipo" obrigatorio classe="full"><select id="campo-tipo" name="tipo" class="input">@foreach (collect(\App\Licitacao\Enums\TipoTermo::opcoes())->except('rescisao') as $v => $r)<option value="{{ $v }}" @selected(old('tipo') === $v)>{{ $r }}</option>@endforeach</select></x-campo>
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

    @php($autoridade = auth()->user()->can('processos.autorizar'))
    <div class="g2">
        <section class="card">
            <h2>Reequilíbrio econômico-financeiro <span class="lei">art. 124, II, d</span></h2>
            <p class="hint">Vale para caso fortuito, força maior, fato do príncipe e fatos imprevisíveis, respeitada a repartição de riscos. Por acordo, não entra no limite de 25% do art. 125.</p>
            @foreach ($contrato->reequilibrios as $r)
                <div class="li" style="display:block;border-top:1px solid #EEF3F7;padding:10px 0">
                    <b>{{ \App\Models\Reequilibrio::FUNDAMENTOS[$r->fundamento] }}</b> · pedido {{ reais($r->valor_pedido) }} em {{ $r->solicitado_em->format('d/m/Y') }}
                    <span class="b {{ $r->situacao === 'deferido' ? 'b-ok' : ($r->situacao === 'indeferido' ? 'b-err' : 'b-info') }}">{{ ['solicitado' => 'Solicitado', 'deferido' => 'Deferido', 'indeferido' => 'Indeferido'][$r->situacao] }}</span>
                    <div class="small muted">{{ $r->motivo }}</div>
                    @if ($r->parecer)<div class="small">Decisão: {{ $r->parecer }}@if ($r->valor_concedido) (concedido {{ reais($r->valor_concedido) }})@endif</div>@endif
                    @if ($autoridade && $r->situacao === 'solicitado')
                        <form method="POST" action="{{ route('reequilibrios.decidir', [$contrato, $r]) }}" class="btns" style="margin-top:6px">@csrf
                            <input name="valor_concedido" class="input" style="width:120px" inputmode="decimal" placeholder="Valor concedido"><input name="parecer" class="input" style="width:230px" placeholder="Parecer" required minlength="20">
                            <button class="btn btn-s btn-sm" name="acao" value="deferir">Deferir</button><button class="btn btn-s btn-sm" name="acao" value="indeferir">Indeferir</button></form>
                    @endif
                </div>
            @endforeach
            @can('contratos.gerenciar')
                @if ($contrato->situacao === 'vigente')
                    <details style="margin-top:8px"><summary class="btn btn-s btn-sm" style="list-style:none">+ Pedir reequilíbrio</summary>
                        <form method="POST" action="{{ route('reequilibrios.store', $contrato) }}" class="form-grid" style="margin-top:12px">@csrf
                            <div class="campo"><label>Fundamento</label><select name="fundamento" class="input">@foreach (\App\Models\Reequilibrio::FUNDAMENTOS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                            <x-campo nome="valor_pedido" rotulo="Valor pedido (R$)" obrigatorio />
                            <div class="campo full"><label>Fato e como desequilibrou o contrato</label><textarea name="motivo" class="input" rows="3" required></textarea></div>
                            <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar pedido</button></div></form>
                    </details>
                @endif
            @endcan
        </section>
        <section class="card">
            <h2>Extinção do contrato <span class="lei">arts. 137 a 139</span></h2>
            <p class="hint">Sempre motivada nos autos, com contraditório e ampla defesa. A rescisão unilateral só conclui depois da defesa ou do fim do prazo.</p>
            @foreach ($contrato->rescisoes as $r)
                <div class="li" style="display:block;border-top:1px solid #EEF3F7;padding:10px 0">
                    <b>{{ \App\Models\Rescisao::MODALIDADES[$r->modalidade] }}</b> <span class="b {{ $r->situacao === 'concluida' ? 'b-ok' : ($r->situacao === 'cancelada' ? 'b-mute' : 'b-warn') }}">{{ ['iniciada' => 'Em andamento', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'][$r->situacao] }}</span>
                    <div class="small muted">{{ \App\Models\Rescisao::MOTIVOS[$r->motivo] }}</div><div class="small">{{ $r->fundamentacao }}</div>
                    @if ($r->modalidade === 'unilateral')<div class="small muted">Comunicado em {{ $r->notificado_em?->format('d/m/Y') }}, defesa até {{ $r->defesa_ate?->format('d/m/Y') }}@if ($r->defesa_recebida_em) · defesa recebida em {{ $r->defesa_recebida_em->format('d/m/Y') }}@endif</div>@endif
                    @if ($r->situacao === 'iniciada')
                        @can('contratos.gerenciar')
                            @if ($r->modalidade === 'unilateral' && ! $r->defesa_recebida_em)
                                <form method="POST" action="{{ route('rescisoes.defesa', [$contrato, $r]) }}" class="btns" style="margin-top:6px">@csrf<input name="defesa" class="input" style="width:260px" placeholder="Resumo da defesa do contratado" required minlength="10"><button class="btn btn-s btn-sm" type="submit">Registrar defesa</button></form>
                            @endif
                            <form method="POST" action="{{ route('rescisoes.cancelar', [$contrato, $r]) }}" style="margin-top:6px">@csrf<button class="btn btn-s btn-sm" type="submit">Cancelar extinção</button></form>
                        @endcan
                        @if ($autoridade)
                            <form method="POST" action="{{ route('rescisoes.concluir', [$contrato, $r]) }}" class="btns" style="margin-top:6px">@csrf
                                <input type="date" name="data_efeito" class="input" style="width:150px" aria-label="Data de efeito" required>
                                <label class="check"><input type="checkbox" name="culpa_administracao" value="1"> Culpa da Administração</label>
                                <input name="valor_indenizacao" class="input" style="width:130px" inputmode="decimal" placeholder="Ressarcimento (R$)">
                                <button class="btn btn-p btn-sm" type="submit">Concluir extinção</button></form>
                        @endif
                    @endif
                </div>
            @endforeach
            @can('contratos.gerenciar')
                @if ($contrato->situacao === 'vigente')
                    <details style="margin-top:8px"><summary class="btn btn-s btn-sm" style="list-style:none">+ Iniciar extinção</summary>
                        <form method="POST" action="{{ route('rescisoes.store', $contrato) }}" class="form-grid" style="margin-top:12px">@csrf
                            <div class="campo"><label>Modalidade (art. 138)</label><select name="modalidade" class="input">@foreach (\App\Models\Rescisao::MODALIDADES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                            <div class="campo"><label>Motivo (art. 137)</label><select name="motivo" class="input">@foreach (\App\Models\Rescisao::MOTIVOS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
                            <x-campo nome="notificado_em" rotulo="Comunicação ao contratado (unilateral)" tipo="date" /><x-campo nome="defesa_ate" rotulo="Prazo da defesa até (unilateral)" tipo="date" />
                            <div class="campo full"><label>Fundamentação nos autos</label><textarea name="fundamentacao" class="input" rows="3" required></textarea></div>
                            <div class="form-acoes full"><button class="btn btn-p" type="submit">Iniciar extinção</button></div></form>
                    </details>
                @endif
            @endcan
        </section>
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
