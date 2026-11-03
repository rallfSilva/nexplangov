<x-layouts.auth titulo="Verificar autorização de adesão">
    <h1 class="h1">Verificação de autorização de adesão</h1>
    @if ($a && $a->situacao === 'autorizada')
        <div class="flash flash-ok" role="status"><x-icone nome="ok" style="flex:none" /><div>Autorização válida, emitida pelo órgão gerenciador no NexPlanGov.@if ($a->prazoEncerrado()) O prazo para contratar terminou em {{ $a->prazo_contratacao->format('d/m/Y') }}.@endif</div></div>
        @include('carona._resumo')
        <p class="small muted" style="margin-top:8px">Hash SHA-256 do PDF: <code style="word-break:break-all">{{ $a->autorizacao_hash }}</code></p>
        <p><a class="btn btn-s" href="{{ route('carona.autorizacao', $a->codigo_verificacao) }}">Baixar o PDF original</a></p>
    @else
        <div class="flash flash-erro" role="alert"><x-icone nome="alerta" style="flex:none" /><div>Nenhuma autorização válida com o código {{ $codigo }}.</div></div>
    @endif
</x-layouts.auth>
