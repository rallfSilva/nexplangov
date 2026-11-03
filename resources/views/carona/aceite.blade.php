<x-layouts.auth titulo="Aceite do fornecedor">
    <h1 class="h1">Pedido de adesão à ata</h1>
    <p class="sub">{{ $a->ataItem->ata->fornecedor->razao_social }}, um órgão que não participou da licitação pediu para contratar pela sua ata. Pela lei, a adesão depende do seu aceite. <span class="lei">art. 86, § 2º, III</span></p>
    <x-flash />
    @include('carona._resumo')

    @if ($a->fornecedor_aceite === null && $a->situacao === 'solicitada')
        <form method="POST" action="{{ request()->fullUrl() }}" class="form-grid" style="margin-top:14px">@csrf
            <x-campo nome="resposta" rotulo="Resposta" obrigatorio classe="full">
                <select id="campo-resposta" name="resposta" class="input" required>
                    <option value="aceito">Aceito fornecer nas condições da ata, sem prejuízo das obrigações com o gerenciador e os participantes</option>
                    <option value="recusado">Não aceito</option>
                </select>
            </x-campo>
            <x-campo nome="representante" rotulo="Nome e cargo de quem responde pela empresa" obrigatorio classe="full" />
            <x-campo nome="obs" rotulo="Observação (obrigatória se recusar)" classe="full" />
            <div class="campo full"><label style="display:flex;gap:8px;align-items:flex-start;font-weight:400"><input type="checkbox" name="ciente" value="1" required> Declaro que tenho poderes para responder pela empresa e que a resposta fica registrada com data e hora.</label></div>
            <div class="form-acoes full"><button class="btn btn-p" type="submit">Enviar resposta</button></div>
        </form>
    @else
        <p class="small muted" style="margin-top:12px">Este pedido já foi respondido.</p>
    @endif
</x-layouts.auth>
