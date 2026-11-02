<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>{{ $titulo ?? 'Entrar' }} · NexPlanGov</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="auth">
    <section class="auth-hero">
        <div class="brand">
            <img src="{{ asset('marca/logo-nexplangov-branco.svg') }}" alt="NexPlanGov, por NexMind" width="240" height="51">
        </div>
        <div>
            <h1>Do plano anual ao contrato, tudo conforme a Lei 14.133.</h1>
            <p>PCA, demandas, ETP, termo de referência, pesquisa de preços, contratos e publicação no PNCP, com cada passo registrado e acesso por perfil.</p>
            <div class="auth-pontos">
                <div><span><x-icone nome="cadeado" /></span>Acesso por perfil, com verificação em duas etapas</div>
                <div><span><x-icone nome="auditoria" /></span>Trilha de auditoria de tudo o que é feito</div>
                <div><span><x-icone nome="pncp" /></span>Publicação no PNCP com fila e reenvio automático</div>
            </div>
        </div>
        <small style="color:#A9D2EE">Dados protegidos conforme a LGPD (Lei nº 13.709/2018). Desenvolvido por <a href="https://nexmind.net.br/" target="_blank" rel="noopener noreferrer" style="color:#D1ECFF">NexMind</a>.</small>
    </section>
    <section class="auth-form">
        <div class="auth-box">
            {{ $slot }}
        </div>
    </section>
</div>
</body>
</html>
