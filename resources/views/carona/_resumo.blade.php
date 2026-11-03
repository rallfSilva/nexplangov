@php($ai = $a->ataItem)
<dl class="dl">
    <div><dt>Protocolo</dt><dd><b>{{ $a->protocolo }}</b></dd></div>
    <div><dt>Situação</dt><dd><span class="b {{ $a->situacao === 'autorizada' && ! $a->prazoEncerrado() ? 'b-ok' : ($a->situacao === 'negada' || $a->fornecedor_aceite === 'recusado' ? 'b-err' : 'b-info') }}">{{ $a->situacaoExibida() }}</span></dd></div>
    <div><dt>Órgão aderente</dt><dd>{{ $a->aderente_nome }} · {{ $a->aderente_cnpj }}</dd></div>
    <div><dt>Ata</dt><dd>{{ $ai->ata->identificacao() }} · {{ $ai->ata->orgao->nome }}</dd></div>
    <div><dt>Fornecedor</dt><dd>{{ $ai->ata->fornecedor->razao_social }}</dd></div>
    <div><dt>Item</dt><dd>{{ $ai->item->numero }}: {{ \Illuminate\Support\Str::limit($ai->item->descricao, 100) }}</dd></div>
    <div><dt>Quantidade</dt><dd>{{ numero_br($a->quantidade, 2) }} {{ $ai->item->unidade_medida }} a {{ reais($ai->valor_unitario, 4) }}</dd></div>
    @if ($a->fornecedor_aceite)<div><dt>Fornecedor</dt><dd>{{ $a->fornecedor_aceite === 'aceito' ? 'Aceitou' : 'Recusou' }} em {{ $a->fornecedor_aceite_em->format('d/m/Y') }}@if ($a->fornecedor_aceite_obs) · {{ $a->fornecedor_aceite_obs }}@endif</dd></div>@endif
    @if ($a->decidida_em)<div><dt>Decisão do gerenciador</dt><dd>{{ $a->situacao === 'autorizada' ? 'Autorizada' : 'Negada' }} em {{ $a->decidida_em->format('d/m/Y') }}@if ($a->motivo_decisao) · {{ $a->motivo_decisao }}@endif</dd></div>@endif
    @if ($a->prazo_contratacao)<div><dt>Contratar até</dt><dd>{{ $a->prazo_contratacao->format('d/m/Y') }}</dd></div>@endif
</dl>
