<x-layouts.auth titulo="Verificação em duas etapas">
    <div>
        <h2>Verificação em duas etapas</h2>
        <p class="sub">Abra o aplicativo autenticador no celular e digite o código de 6 dígitos.</p>
    </div>

    <form method="POST" action="{{ route('two-factor.login.store') }}" style="display:flex;flex-direction:column;gap:16px">
        @csrf
        <x-campo nome="code" rotulo="Código do aplicativo" inputmode="numeric" autocomplete="one-time-code" autofocus maxlength="6" />
        <details>
            <summary style="cursor:pointer;font-size:14px;font-weight:600;color:#34729C">Perdi o celular: usar um código de recuperação</summary>
            <div style="margin-top:12px">
                <x-campo nome="recovery_code" rotulo="Código de recuperação" autocomplete="off" />
            </div>
        </details>
        <button type="submit" class="btn btn-p btn-full">Confirmar</button>
    </form>
</x-layouts.auth>
