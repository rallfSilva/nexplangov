<?php

namespace App\Http\Controllers;

use App\Models\CatalogoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Consulta ao catálogo CATMAT/CATSER. */
class CatalogoController extends Controller
{
    public function index(Request $request): View
    {
        $d = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'tipo' => ['nullable', 'in:M,S']]);
        $q = trim($d['q'] ?? '');

        return view('catalogo.index', [
            'q' => $q, 'tipo' => $d['tipo'] ?? '',
            'itens' => $q !== '' ? CatalogoItem::buscar($q, $d['tipo'] ?? null)->orderBy('descricao')->limit(50)->get() : collect(),
            'total' => ['M' => CatalogoItem::where('tipo', 'M')->count(), 'S' => CatalogoItem::where('tipo', 'S')->count()],
        ]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'tipo' => ['nullable', 'in:M,S']]);

        return response()->json(CatalogoItem::buscar($d['q'], $d['tipo'] ?? null)->where('ativo', true)->orderBy('descricao')->limit(15)
            ->get(['tipo', 'codigo', 'descricao', 'unidade_padrao']));
    }
}
