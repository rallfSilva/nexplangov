<x-layouts.auth titulo="Confirmar senha">
    <div>
        <h2>Confirme sua senha</h2>
        <p class="sub">Esta é uma área protegida. Digite sua senha para continuar.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm.store') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <x-campo nome="password" rotulo="Senha" tipo="password" autocomplete="current-password" autofocus obrigatorio />
        <button type="submit" class="btn btn-p btn-full">Confirmar</button>
    </form>
</x-layouts.auth>
