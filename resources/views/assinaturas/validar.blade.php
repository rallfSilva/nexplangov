<x-layouts.auth titulo="Validar assinatura">
    <h1 class="h1">Validar assinatura eletrônica</h1>
    <p class="sub">Digite o código que aparece na folha de assinaturas ou leia o QR Code.</p>

    <form method="POST" action="{{ route('assinaturas.buscar') }}" class="form-grid" novalidate>
        @csrf
        <div class="campo full"><label for="codigo">Código de validação</label><input id="codigo" name="codigo" class="input" value="{{ $codigo ? \App\Licitacao\Fluxo\Assinaturas::formatarCodigo($codigo) : '' }}" placeholder="XXXX-XXXX-XXXX" autocomplete="off" required>
            @error('codigo')<span class="erro">{{ $message }}</span>@enderror</div>
        <div class="form-acoes full"><button class="btn btn-p" type="submit"><x-icone nome="busca" />Validar</button></div>
    </form>

    @if ($codigo)
        <div style="margin-top:20px">
            @if (! $assinatura)
                <div class="aviso-lei" style="border-color:#C94A4A;background:#FDECEC;color:#7A1F1F"><b>Código não encontrado.</b> Confira se digitou corretamente.</div>
            @else
                @php($doc = $assinatura->documento)
                <div class="aviso-lei" @if (! $valida['valida']) style="border-color:#C94A4A;background:#FDECEC;color:#7A1F1F" @endif>
                    @if ($valida['valida'])<b>Assinatura válida.</b> A peça não foi alterada desde a assinatura.@else<b>Assinatura inválida.</b> {{ $valida['motivo'] }}@endif
                </div>
                <dl class="dl" style="margin-top:14px">
                    <div><dt>Peça</dt><dd>{{ \App\Licitacao\TiposDocumento::get($doc->tipo)['rotulo'] }} (versão {{ $doc->versao }})</dd></div>
                    <div><dt>Processo</dt><dd>{{ $doc->processo->identificacao() }}</dd></div>
                    <div><dt>Órgão</dt><dd>{{ $doc->processo->orgao->nome }}</dd></div>
                    <div><dt>Assinante</dt><dd>{{ $assinatura->nome }} · {{ $assinatura->perfil }}</dd></div>
                    <div><dt>Data e hora</dt><dd>{{ $assinatura->assinado_em->format('d/m/Y H:i:s') }}</dd></div>
                </dl>
                <p class="small muted">Por segurança, o conteúdo da peça não é exibido aqui.</p>
            @endif
        </div>
    @endif
</x-layouts.auth>
