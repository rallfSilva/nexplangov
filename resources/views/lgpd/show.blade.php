<x-layouts.app :titulo="'Pedido '.$solicitacao->protocolo">
    <div>
        <a href="{{ route('lgpd.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />LGPD</a>
        <h1 class="h1" style="margin-top:8px">Pedido {{ $solicitacao->protocolo }}</h1>
        <p class="sub">{{ \App\Models\SolicitacaoTitular::TIPOS[$solicitacao->tipo] }} · prazo {{ $solicitacao->prazo_em->format('d/m/Y') }}</p>
    </div>
    <div class="g2">
        <section class="card">
            <h2>Titular</h2>
            <dl class="dados">
                <div><dt>Nome</dt><dd>{{ $solicitacao->nome }}</dd></div>
                <div><dt>E-mail</dt><dd>{{ $solicitacao->email }}</dd></div>
                <div><dt>CPF</dt><dd>{{ $solicitacao->cpf_cifrado ? \App\Support\Documento::mascararCpf($solicitacao->cpf_cifrado) : 'Não informado' }}</dd></div>
                <div><dt>Recebido</dt><dd>{{ $solicitacao->created_at->format('d/m/Y H:i') }}</dd></div>
            </dl>
            <p style="margin-top:16px;line-height:1.6">{{ $solicitacao->descricao }}</p>
        </section>
        <section class="card">
            <h2>Atendimento</h2>
            <form method="POST" action="{{ route('lgpd.responder', $solicitacao) }}" class="form-grid" style="margin-top:14px">
                @csrf @method('PATCH')
                <x-campo nome="situacao" rotulo="Situação" obrigatorio classe="full"><select id="campo-situacao" name="situacao" class="input">@foreach (\App\Models\SolicitacaoTitular::SITUACOES as $v => $r)<option value="{{ $v }}" @selected(old('situacao', $solicitacao->situacao) === $v)>{{ $r }}</option>@endforeach</select></x-campo>
                <x-campo nome="resposta" rotulo="Resposta ao titular" classe="full"><textarea id="campo-resposta" name="resposta" class="input" rows="6">{{ old('resposta', $solicitacao->resposta) }}</textarea></x-campo>
                <div class="campo"><button class="btn btn-p" type="submit">Salvar</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
