<?php

namespace App\Http\Controllers;

use App\Licitacao\Fluxo\Assinaturas;
use App\Models\Assinatura;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Página pública de validação (sem login). Mostra só metadados: o tipo da peça, o processo, o órgão,
 * quem assinou e quando. Nunca mostra o conteúdo da peça.
 */
class ValidacaoAssinaturaController extends Controller
{
    public function __construct(private Assinaturas $assinaturas) {}

    public function buscar(Request $request): RedirectResponse
    {
        $d = $request->validate(['codigo' => ['required', 'string', 'max:40']]);

        return redirect()->route('assinaturas.validar', preg_replace('/[^A-Z0-9]/', '', strtoupper($d['codigo'])));
    }

    public function mostrar(?string $codigo = null): View
    {
        $assinatura = null;
        $limpo = $codigo ? preg_replace('/[^A-Z0-9]/', '', strtoupper($codigo)) : null;

        if ($limpo) {
            // Consulta pública: o escopo do órgão não se aplica porque não há usuário autenticado.
            $assinatura = Assinatura::with(['documento.processo.orgao'])->where('codigo', $limpo)->first();
        }

        return view('assinaturas.validar', [
            'codigo' => $limpo, 'assinatura' => $assinatura,
            'valida' => $assinatura ? $this->assinaturas->verificar($assinatura) : null,
        ]);
    }
}
