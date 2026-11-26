<?php

use App\Http\Middleware\CabecalhosSeguranca;
use App\Http\Middleware\ExigirDoisFatores;
use App\Http\Middleware\GarantirUsuarioAtivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ativo' => GarantirUsuarioAtivo::class,
            'dois-fatores' => ExigirDoisFatores::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
        $middleware->append(CabecalhosSeguranca::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/painel');
        // Defina TRUSTED_PROXIES só quando houver um proxy reverso na frente (ex.: 10.0.0.0/8). Sem isso, o IP gravado na auditoria é o da conexão.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Operação recusada por regra (abort 422) numa tela: volta ao formulário com a explicação e os dados digitados,
        // em vez da página de erro crua. Em GET, a página errors/422 mostra a mesma explicação.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 422 || $request->is('api/*') || $request->expectsJson() || $request->isMethod('GET')) {
                return null;
            }

            return back()->withInput($request->except(['password', 'password_confirmation', '_token']))
                ->with('erro', $e->getMessage() !== '' ? $e->getMessage() : 'Não foi possível concluir a operação. Confira os dados e tente de novo.');
        });
    })->create();
