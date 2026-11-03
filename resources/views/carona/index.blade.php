<x-layouts.auth titulo="Adesão a atas de registro de preços">
    <h1 class="h1">Adesão a ata de registro de preços</h1>
    <p class="sub">Órgão que não participou da licitação pode pedir para usar a ata (a "carona"), sem conta no sistema. <span class="lei">art. 86, § 2º</span></p>
    <x-flash />
    <form method="GET" action="{{ route('carona.index') }}" class="btns" role="search" style="margin:10px 0">
        <input id="busca-carona" name="q" aria-label="Buscar ata" value="{{ $busca }}" class="input" style="flex:1;min-width:180px" placeholder="Objeto, item ou órgão gerenciador">
        <button class="btn btn-s" type="submit">Buscar</button>
    </form>
    <p class="small"><a href="{{ route('carona.consulta') }}" style="font-weight:600">Já pediu? Acompanhe pelo protocolo</a></p>

    @forelse ($atas as $ata)
        <div class="aviso-lei" style="margin:8px 0">
            <b><a href="{{ route('carona.ata', $ata) }}">Ata {{ $ata->identificacao() }}</a></b> · {{ $ata->orgao->nome }}
            <div class="small">{{ \Illuminate\Support\Str::limit($ata->processo->objeto, 140) }}</div>
            <div class="small muted">Fornecedor {{ $ata->fornecedor->razao_social }} · vigente até {{ $ata->vigencia_fim->format('d/m/Y') }}</div>
        </div>
    @empty
        <p class="muted small">Nenhuma ata vigente encontrada.</p>
    @endforelse
    <div class="paginacao">{{ $atas->links() }}</div>

    <div class="aviso-lei small" style="margin-top:16px">Como funciona: 1) escolha a ata e o item; 2) envie o pedido com o ofício da autoridade do seu órgão; 3) o fornecedor diz se aceita fornecer; 4) o órgão gerenciador autoriza ou nega e emite o ofício de autorização com QR Code. Depois da autorização, seu órgão tem prazo para contratar. Órgão federal não pode aderir a ata estadual, distrital ou municipal <span class="lei">art. 86, § 8º</span>.</div>
</x-layouts.auth>
