<x-layouts.auth titulo="Criar nova senha">
    <div>
        <h2>Criar nova senha</h2>
        <p class="sub">Mínimo de 10 caracteres, com letra maiúscula, letra minúscula e número.</p>
    </div>

    <form method="POST" action="{{ route('password.update') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <x-campo nome="email" rotulo="E-mail" tipo="email" :valor="$request->email" autocomplete="username" obrigatorio />
        <x-campo nome="password" rotulo="Nova senha" tipo="password" autocomplete="new-password" obrigatorio />
        <x-campo nome="password_confirmation" rotulo="Repita a nova senha" tipo="password" autocomplete="new-password" obrigatorio />
        <button type="submit" class="btn btn-p btn-full">Salvar senha</button>
    </form>
</x-layouts.auth>
