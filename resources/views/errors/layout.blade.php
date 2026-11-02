<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titulo') · NexPlanGov</title>
    @vite(['resources/css/app.css'])
</head>
<body>
<main style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px">
    <div class="card" style="max-width:480px;text-align:center;display:flex;flex-direction:column;gap:14px;align-items:center;padding:40px 32px">
        <span class="logo" aria-hidden="true">N</span>
        <p class="muted small" style="margin:0;font-weight:700;letter-spacing:.08em">ERRO @yield('codigo')</p>
        <h1 class="h1" style="font-size:24px">@yield('titulo')</h1>
        <p class="sub" style="margin:0">@yield('mensagem')</p>
        <a href="{{ url('/painel') }}" class="btn btn-p">Voltar ao painel</a>
    </div>
</main>
</body>
</html>
