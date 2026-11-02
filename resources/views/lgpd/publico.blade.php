<x-layouts.auth titulo="Seus dados pessoais">
    <h2>Seus dados pessoais</h2>
    <p class="muted" style="margin:0">Peça confirmação, acesso, correção ou eliminação dos seus dados (LGPD, art. 18). A resposta chega por e-mail em até 15 dias.</p>
    <x-flash />
    <form method="POST" action="{{ route('lgpd.publico.registrar') }}" style="display:flex;flex-direction:column;gap:14px" novalidate>
        @csrf
        <input type="text" name="site" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
        <x-campo nome="orgao_id" rotulo="Órgão" obrigatorio><select id="campo-orgao_id" name="orgao_id" class="input"><option value="">Escolha</option>@foreach ($orgaos as $o)<option value="{{ $o->id }}" @selected((int) old('orgao_id') === $o->id)>{{ $o->nome }}</option>@endforeach</select></x-campo>
        <x-campo nome="nome" rotulo="Seu nome" obrigatorio autocomplete="name" />
        <x-campo nome="email" rotulo="Seu e-mail" tipo="email" obrigatorio autocomplete="email" />
        <x-campo nome="cpf" rotulo="CPF (opcional, ajuda a localizar seus dados)" inputmode="numeric" />
        <x-campo nome="tipo" rotulo="O que você quer" obrigatorio><select id="campo-tipo" name="tipo" class="input">@foreach (\App\Models\SolicitacaoTitular::TIPOS as $v => $r)<option value="{{ $v }}" @selected(old('tipo') === $v)>{{ $r }}</option>@endforeach</select></x-campo>
        <x-campo nome="descricao" rotulo="Detalhe o pedido" obrigatorio><textarea id="campo-descricao" name="descricao" class="input" rows="4">{{ old('descricao') }}</textarea></x-campo>
        <label class="check" style="align-items:flex-start"><input type="checkbox" name="ciente" value="1" style="margin-top:3px"> <span class="small">Entendo que meu nome, e-mail e CPF serão usados só para atender este pedido e ficarão guardados pelo prazo legal. O CPF é cifrado.</span></label>
        @error('ciente')<span class="erro small" style="color:#9F1D1D">{{ $message }}</span>@enderror
        <button class="btn btn-p btn-full" type="submit">Enviar pedido</button>
        <a href="{{ route('privacidade') }}" class="small">Ler o aviso de privacidade</a>
    </form>
</x-layouts.auth>
