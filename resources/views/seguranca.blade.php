<x-layouts.app titulo="Minha segurança">
    <div>
        <h1 class="h1">Minha segurança</h1>
        <p class="sub">Senha e verificação em duas etapas da sua conta.</p>
    </div>

    <div class="g2">
        <section class="card">
            <h2>Verificação em duas etapas</h2>
            <p class="hint">Além da senha, o sistema pede um código do aplicativo autenticador do celular (Google Authenticator, Microsoft Authenticator ou outro).</p>

            @if (! $senhaConfirmada)
                <p class="small" style="margin:16px 0 12px">Para ativar ou mudar a verificação em duas etapas, confirme sua senha primeiro.</p>
                <a href="{{ route('password.confirm') }}" class="btn btn-p"><x-icone nome="cadeado" />Confirmar senha e continuar</a>
            @elseif ($user->temDoisFatoresAtivo())
                <div class="flash flash-ok" style="margin-top:16px"><x-icone nome="ok" style="flex:none" /><div><b>Ativada</b> desde {{ $user->two_factor_confirmed_at->format('d/m/Y') }}.</div></div>

                @if (session('status') === 'recovery-codes-generated' || session('status') === 'two-factor-authentication-confirmed')
                    <p class="small" style="margin:16px 0 8px"><b>Guarde estes códigos de recuperação</b> em local seguro. Cada um funciona uma vez, se você perder o celular.</p>
                    <div class="codigos">@foreach ($user->recoveryCodes() as $codigo)<span>{{ $codigo }}</span>@endforeach</div>
                @endif

                <div class="btns" style="margin-top:16px">
                    <form method="POST" action="{{ route('two-factor.regenerate-recovery-codes') }}">@csrf
                        <button type="submit" class="btn btn-s">Gerar novos códigos de recuperação</button>
                    </form>
                    @unless ($user->exigeDoisFatores() && config('nexplangov.exigir_2fa'))
                        <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')
                            <button type="submit" class="btn btn-d">Desativar</button>
                        </form>
                    @endunless
                </div>
            @elseif ($user->two_factor_secret)
                <ol class="small" style="margin:16px 0;padding-left:18px;line-height:1.7;list-style:decimal">
                    <li>Abra o aplicativo autenticador e leia o QR Code abaixo.</li>
                    <li>Digite o código de 6 dígitos que aparecer no aplicativo.</li>
                </ol>
                <div class="qr">{!! $user->twoFactorQrCodeSvg() !!}</div>
                <p class="muted small">Sem câmera? Digite a chave: <code>{{ decrypt($user->two_factor_secret) }}</code></p>
                <form method="POST" action="{{ route('two-factor.confirm') }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:8px">
                    @csrf
                    <div class="campo" style="flex:1 1 180px">
                        <label for="codigo-2fa">Código do aplicativo</label>
                        <input id="codigo-2fa" name="code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                        @error('code', 'confirmTwoFactorAuthentication')<span class="erro">{{ $message }}</span>@enderror
                    </div>
                    <button type="submit" class="btn btn-p">Confirmar e ativar</button>
                </form>
            @else
                <form method="POST" action="{{ route('two-factor.enable') }}" style="margin-top:16px">@csrf
                    <button type="submit" class="btn btn-p"><x-icone nome="escudo" />Ativar verificação em duas etapas</button>
                </form>
            @endif
        </section>

        <section class="card">
            <h2>Trocar senha</h2>
            <p class="hint">Mínimo de 10 caracteres, com letra maiúscula, letra minúscula e número.</p>
            <form method="POST" action="{{ route('user-password.update') }}" style="display:flex;flex-direction:column;gap:14px;margin-top:16px">
                @csrf @method('PUT')
                @foreach (['current_password' => ['Senha atual', 'current-password'], 'password' => ['Nova senha', 'new-password'], 'password_confirmation' => ['Repita a nova senha', 'new-password']] as $nome => [$rotulo, $auto])
                    <div class="campo">
                        <label for="s-{{ $nome }}">{{ $rotulo }}</label>
                        <input id="s-{{ $nome }}" type="password" name="{{ $nome }}" class="input" autocomplete="{{ $auto }}" required>
                        @error($nome, 'updatePassword')<span class="erro">{{ $message }}</span>@enderror
                    </div>
                @endforeach
                <div><button type="submit" class="btn btn-p">Salvar nova senha</button></div>
            </form>
        </section>
    </div>
</x-layouts.app>
