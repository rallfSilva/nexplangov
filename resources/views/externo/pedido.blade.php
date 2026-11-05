<x-layouts.auth :titulo="$s->rotuloTipo()">
    <h1 class="h1">{{ $s->rotuloTipo() }}</h1>
    <p class="sub">{{ $s->processo->orgao?->nome }} · processo {{ $s->processo->identificacao() }}</p>
    <x-flash />

    <div class="card" style="margin-top:12px">
        <p style="margin:0 0 6px"><b>Para:</b> {{ $s->nome }}</p>
        <p style="margin:0 0 6px"><b>Objeto do processo:</b> {{ $s->processo->objeto }}</p>
        <p style="margin:0 0 6px;white-space:pre-line">{{ $s->mensagem }}</p>
        <p class="small muted" style="margin:0">Prazo: até {{ $s->prazo_em->format('d/m/Y') }}.</p>
    </div>

    @if ($s->tipo === 'assinatura' && $s->documento && $def)
        <section class="card" style="margin-top:12px">
            <h2>{{ $def['rotulo'] }} <span class="lei">{{ $def['base'] }}</span></h2>
            @if ($s->documento->origem === 'arquivo')
                <p class="small">A peça foi enviada como arquivo ({{ $s->documento->arquivo_nome }}). Impressão digital SHA-256: <code style="word-break:break-all">{{ $s->documento->arquivo_hash }}</code></p>
            @else
                @foreach (($def['campos'] ?? []) as $campo => $c)
                    <h3 style="font-size:14px;margin:14px 0 4px">{{ $c['rotulo'] }}</h3>
                    <p style="margin:0;white-space:pre-line">{{ trim((string) ($s->documento->conteudo[$campo] ?? '')) ?: 'Não preenchido.' }}</p>
                @endforeach
            @endif
            <p class="small muted" style="margin-top:12px">Impressão digital do conteúdo que você vai assinar (SHA-256): <code style="word-break:break-all">{{ $s->documento->hashAtual() }}</code></p>
        </section>
    @endif

    @if ($bloqueio)
        <div class="flash flash-aviso" role="status" style="margin-top:12px"><x-icone nome="alerta" style="flex:none" /><div>{{ $bloqueio[0] }}</div></div>
    @elseif ($s->tipo === 'assinatura')
        <form method="POST" action="{{ route('externo.responder', request()->route('token')) }}" class="form-grid" style="margin-top:14px">@csrf
            <x-campo nome="nome" rotulo="Seu nome completo, como está no pedido" :obrigatorio="true" classe="full" autocomplete="name" />
            <div class="campo full"><label style="display:flex;gap:8px;align-items:flex-start;font-weight:400"><input type="checkbox" name="ciente" value="1" required> Li o documento acima e o assino eletronicamente. Sei que a assinatura fica registrada com data, hora, endereço de rede e a impressão digital do conteúdo.</label></div>
            <div class="form-acoes full"><button class="btn btn-p" type="submit">Assinar</button></div>
        </form>
    @elseif ($s->tipo === 'documentos')
        <form method="POST" action="{{ route('externo.responder', request()->route('token')) }}" enctype="multipart/form-data" class="form-grid" style="margin-top:14px">@csrf
            <x-campo nome="arquivos" rotulo="Arquivos (até 5; PDF, DOCX, ODT, XLSX, ODS, PNG ou JPG; até 30 MB cada)" :obrigatorio="true" classe="full"><input id="campo-arquivos" type="file" name="arquivos[]" class="input" multiple required accept=".pdf,.docx,.odt,.xlsx,.ods,.png,.jpg,.jpeg"></x-campo>
            <x-campo nome="observacao" rotulo="Observação" classe="full"><textarea id="campo-observacao" name="observacao" class="input" rows="2" maxlength="1000">{{ old('observacao') }}</textarea></x-campo>
            <div class="form-acoes full"><button class="btn btn-p" type="submit">Enviar documentos</button></div>
        </form>
    @else
        <form method="POST" action="{{ route('externo.responder', request()->route('token')) }}" style="margin-top:14px">@csrf
            <div class="campo"><label style="display:flex;gap:8px;align-items:flex-start;font-weight:400"><input type="checkbox" name="ciente" value="1" required> Declaro que tomei ciência desta notificação.</label></div>
            <div class="form-acoes"><button class="btn btn-p" type="submit">Registrar ciência</button></div>
        </form>
    @endif
</x-layouts.auth>
