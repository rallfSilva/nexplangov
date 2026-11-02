<x-layouts.auth titulo="Esqueci a senha">
    <div>
        <h2>Esqueci a senha</h2>
        <p class="sub">Informe seu e-mail. Se ele estiver cadastrado, você recebe um link para criar uma nova senha.</p>
    </div>

    <x-flash />

    <form method="POST" action="{{ route('password.email') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <x-campo nome="email" rotulo="E-mail" tipo="email" autocomplete="username" autofocus obrigatorio />
        <button type="submit" class="btn btn-p btn-full">Enviar link</button>
    </form>

    <a href="{{ route('login') }}" style="font-size:14px;font-weight:600">Voltar para a entrada</a>
</x-layouts.auth>
