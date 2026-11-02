<x-layouts.auth titulo="Entrar">
    <div>
        <h2>Entrar no sistema</h2>
        <p class="sub">Use o e-mail e a senha cadastrados pelo RH do seu órgão.</p>
    </div>

    <x-flash />

    <form method="POST" action="{{ route('login.store') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <x-campo nome="email" rotulo="E-mail" tipo="email" autocomplete="username" autofocus obrigatorio />
        <x-campo nome="password" rotulo="Senha" tipo="password" autocomplete="current-password" obrigatorio />
        <div class="row">
            <label class="check"><input type="checkbox" name="remember" value="1"> Manter conectado neste computador</label>
            <a href="{{ route('password.request') }}" style="font-size:14px;font-weight:600">Esqueci a senha</a>
        </div>
        <button type="submit" class="btn btn-p btn-full">Entrar</button>
    </form>

    <p class="muted small" style="margin:0">Não tem acesso? Peça ao administrador do seu órgão. Por segurança, o cadastro de usuários é feito somente por ele.</p>
</x-layouts.auth>
