{{-- Fase recursal (arts. 165 a 168). Usa $processo, $conduz, $emJulgamento, $encerrada, $autoridade, $licitantes. --}}
<section class="card" id="recursos">
    <h2 style="margin:0">Recursos <span class="lei">arts. 165 a 168</span></h2>
    <p class="hint">A intenção de recorrer é manifestada na sessão. As razões vêm em 3 dias úteis da ata; as contrarrazões, em 3 dias úteis da interposição. O agente reconsidera ou mantém, e a autoridade superior decide em até 10 dias úteis. Enquanto houver recurso pendente, o item não é adjudicado e o processo não é homologado (art. 168).</p>

    @foreach ($processo->recursos as $r)
        @php($sit = $r->situacaoAtual())
        <div class="aviso-lei" style="margin:10px 0">
            <div class="row">
                <div><b>{{ $r->fornecedor->razao_social }}</b> · {{ \App\Models\Recurso::TIPOS[$r->tipo] }}@if ($r->item) · item {{ $r->item->numero }}@else · todos os itens @endif
                    <div class="small muted">Intenção em {{ $r->intencao_em->format('d/m/Y H:i') }}: {{ $r->intencao_motivo }}</div></div>
                <span class="b {{ $r->pendente() ? 'b-info' : ($sit === 'sem_razoes' ? 'b-mute' : 'b-ok') }}">{{ \App\Models\Recurso::SITUACOES[$sit] }}@if ($r->resultado && in_array($sit, ['decidido', 'reconsiderado'], true)): {{ \App\Models\Recurso::RESULTADOS[$r->resultado] }}@endif</span>
            </div>

            <dl class="dados" style="margin-top:6px">
                @if ($r->prazo_razoes)<div><dt>Razões até</dt><dd>{{ $r->prazo_razoes->format('d/m/Y') }}</dd></div>@endif
                @if ($r->prazo_contrarrazoes)<div><dt>Contrarrazões até</dt><dd>{{ $r->prazo_contrarrazoes->format('d/m/Y') }}</dd></div>@endif
                @if ($r->prazo_reconsideracao && $r->situacao === 'razoes')<div><dt>Reconsideração até</dt><dd>{{ $r->prazo_reconsideracao->format('d/m/Y') }}</dd></div>@endif
                @if ($r->prazo_decisao)<div><dt>Decisão até</dt><dd>{{ $r->prazo_decisao->format('d/m/Y') }}</dd></div>@endif
            </dl>

            @if ($r->razoes)<p class="small"><b>Razões ({{ $r->razoes_em->format('d/m/Y') }}):</b> <span style="white-space:pre-line">{{ $r->razoes }}</span></p>@endif
            @foreach ($r->contrarrazoes as $cr)<p class="small"><b>Contrarrazões de {{ $cr->fornecedor->razao_social }}:</b> <span style="white-space:pre-line">{{ $cr->texto }}</span></p>@endforeach
            @if ($r->reconsideracao_texto)<p class="small"><b>{{ $r->reconsideracao === 'reconsiderou' ? 'Reconsiderado pelo agente' : 'Mantido pelo agente' }}:</b> <span style="white-space:pre-line">{{ $r->reconsideracao_texto }}</span></p>@endif
            @if ($r->decisao)<p class="small"><b>Decisão da autoridade:</b> <span style="white-space:pre-line">{{ $r->decisao }}</span></p>@endif

            @if ($conduz && $sit === 'intencao' && $r->prazo_razoes)
                <form method="POST" action="{{ route('recursos.razoes', [$processo, $r]) }}" class="form-grid" style="margin-top:8px">@csrf
                    <div class="campo full"><label for="razoes-{{ $r->id }}">Razões do recurso, como apresentadas pelo licitante</label><textarea id="razoes-{{ $r->id }}" name="razoes" class="input" rows="3" minlength="30" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-s btn-sm" type="submit">Registrar razões</button></div></form>
            @endif

            @if ($conduz && $r->situacao === 'razoes' && ! $r->prontoParaReconsiderar())
                <form method="POST" action="{{ route('recursos.contrarrazoes', [$processo, $r]) }}" class="form-grid" style="margin-top:8px">@csrf
                    <div class="campo"><label for="contra-forn-{{ $r->id }}">Licitante</label><select id="contra-forn-{{ $r->id }}" name="fornecedor_id" class="input" required><option value="">Escolha</option>@foreach ($licitantes->where('id', '!=', $r->fornecedor_id) as $f)<option value="{{ $f->id }}">{{ $f->razao_social }}</option>@endforeach</select></div>
                    <div class="campo full"><label for="contra-{{ $r->id }}">Contrarrazões</label><textarea id="contra-{{ $r->id }}" name="texto" class="input" rows="3" minlength="30" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-s btn-sm" type="submit">Registrar contrarrazões</button></div></form>
            @endif

            @if ($conduz && $r->prontoParaReconsiderar())
                <form method="POST" action="{{ route('recursos.reconsiderar', [$processo, $r]) }}" class="form-grid" style="margin-top:8px">@csrf
                    <div class="campo"><label for="recons-{{ $r->id }}">Decisão do agente</label><select id="recons-{{ $r->id }}" name="decisao" class="input"><option value="manter">Manter o ato e encaminhar à autoridade</option><option value="reconsiderar">Reconsiderar o ato</option></select></div>
                    <div class="campo full"><label for="recons-fund-{{ $r->id }}">Fundamentação</label><textarea id="recons-fund-{{ $r->id }}" name="fundamento" class="input" rows="3" minlength="30" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-s btn-sm" type="submit">Registrar decisão</button></div></form>
            @endif

            @if ($autoridade && $r->situacao === 'superior')
                <form method="POST" action="{{ route('recursos.decidir', [$processo, $r]) }}" class="form-grid" style="margin-top:8px">@csrf
                    <div class="campo"><label for="dec-{{ $r->id }}">Resultado</label><select id="dec-{{ $r->id }}" name="resultado" class="input">@foreach (\App\Models\Recurso::RESULTADOS as $k => $rot)<option value="{{ $k }}">{{ $rot }}</option>@endforeach</select></div>
                    <div class="campo full"><label for="dec-fund-{{ $r->id }}">Fundamentação da decisão</label><textarea id="dec-fund-{{ $r->id }}" name="fundamento" class="input" rows="3" minlength="30" required></textarea></div>
                    <div class="form-acoes full"><button class="btn btn-p btn-sm" type="submit">Decidir recurso</button></div></form>
            @endif

            @if (in_array($sit, ['reconsiderado', 'decidido'], true) && in_array($r->resultado, ['provido', 'parcial'], true))
                <p class="small"><b>Próximo passo:</b> refaça na sessão o ato que a decisão mandou corrigir (habilitação, desclassificação ou classificação). Se precisar de lance ou proposta nova, reabra a sessão.</p>
            @endif
        </div>
    @endforeach

    @if ($processo->recursos->isEmpty())<p class="muted small">Nenhuma intenção de recurso registrada.</p>@endif

    @if ($conduz && $emJulgamento && ! $encerrada && $licitantes->isNotEmpty())
        <form method="POST" action="{{ route('recursos.intencao', $processo) }}" class="form-grid" style="margin-top:12px">@csrf
            <div class="campo"><label for="int-forn">Licitante que quer recorrer</label><select id="int-forn" name="fornecedor_id" class="input" required><option value="">Escolha</option>@foreach ($licitantes as $f)<option value="{{ $f->id }}">{{ $f->razao_social }}</option>@endforeach</select></div>
            <div class="campo"><label for="int-tipo">Contra</label><select id="int-tipo" name="tipo" class="input">@foreach (\App\Models\Recurso::TIPOS as $k => $rot)<option value="{{ $k }}">{{ $rot }}</option>@endforeach</select></div>
            <div class="campo"><label for="int-item">Item</label><select id="int-item" name="item_id" class="input"><option value="">Todos os itens</option>@foreach ($processo->itens as $it)<option value="{{ $it->id }}">Item {{ $it->numero }}</option>@endforeach</select></div>
            <div class="campo full"><label for="int-motivo">Motivo resumido, como dito na sessão</label><input id="int-motivo" name="motivo" class="input" minlength="10" required></div>
            <div class="form-acoes full"><button class="btn btn-s btn-sm" type="submit">Registrar intenção de recurso</button></div></form>
    @endif
</section>
