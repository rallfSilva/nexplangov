<?php

namespace App\Http\Controllers;

use App\Models\RegistroAuditoria;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditoriaController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate([
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
        ]);

        $registros = RegistroAuditoria::with(['usuario', 'orgao'])
            ->when($request->filled('evento'), fn ($q) => $q->where('evento', $request->string('evento')))
            ->when($request->filled('usuario'), function ($q) use ($request) {
                $termo = '%'.mb_strtolower($request->string('usuario')).'%';
                $q->whereHas('usuario', fn ($u) => $u->whereRaw('lower(name) like ?', [$termo])->orWhereRaw('lower(email) like ?', [$termo]));
            })
            ->when($request->filled('busca'), fn ($q) => $q->whereRaw('lower(descricao) like ?', ['%'.mb_strtolower($request->string('busca')).'%']))
            ->when($request->filled('de'), fn ($q) => $q->where('created_at', '>=', $request->date('de')->startOfDay()))
            ->when($request->filled('ate'), fn ($q) => $q->where('created_at', '<=', $request->date('ate')->endOfDay()))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('auditoria.index', ['registros' => $registros, 'eventos' => RegistroAuditoria::EVENTOS]);
    }
}
