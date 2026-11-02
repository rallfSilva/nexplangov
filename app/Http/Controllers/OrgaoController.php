<?php

namespace App\Http\Controllers;

use App\Models\Orgao;
use App\Rules\Cnpj;
use Database\Seeders\FluxosPadrao;
use Database\Seeders\ModelosPadrao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrgaoController extends Controller
{
    public function index(): View
    {
        return view('orgaos.index', [
            'orgaos' => Orgao::withCount(['unidades', 'usuarios'])->orderBy('sigla')->get(),
        ]);
    }

    public function create(): View
    {
        return view('orgaos.form', ['orgao' => new Orgao(['esfera' => 'municipal', 'poder' => 'executivo', 'pncp_ambiente' => 'homologacao', 'ativo' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $orgao = Orgao::create($this->validar($request));
        ModelosPadrao::instalarPara($orgao);
        FluxosPadrao::instalarPara($orgao);

        return redirect()->route('orgaos.index')->with('sucesso', "Órgão {$orgao->sigla} cadastrado.");
    }

    public function edit(Orgao $orgao): View
    {
        return view('orgaos.form', ['orgao' => $orgao]);
    }

    public function update(Request $request, Orgao $orgao): RedirectResponse
    {
        $orgao->update($this->validar($request, $orgao));

        return redirect()->route('orgaos.index')->with('sucesso', "Órgão {$orgao->sigla} atualizado.");
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Orgao $orgao = null): array
    {
        $request->merge(['sigla' => mb_strtoupper(trim((string) $request->input('sigla')))]);

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'sigla' => ['required', 'string', 'max:30', Rule::unique('orgaos', 'sigla')->ignore($orgao)],
            'cnpj' => ['nullable', 'string', new Cnpj, Rule::unique('orgaos', 'cnpj')->ignore($orgao)],
            'esfera' => ['required', Rule::in(['municipal', 'estadual', 'distrital', 'federal'])],
            'poder' => ['nullable', Rule::in(['executivo', 'legislativo', 'judiciario', 'autonomo'])],
            'natureza_juridica' => ['nullable', 'integer', 'between:1000,9999'],
            'uf' => ['required', 'string', 'size:2'],
            'municipio' => ['required', 'string', 'max:255'],
            'pncp_ambiente' => ['nullable', Rule::in(['homologacao', 'producao'])],
        ]);

        $dados['uf'] = mb_strtoupper($dados['uf']);
        $dados['poder'] ??= 'executivo';
        $dados['pncp_ambiente'] ??= 'homologacao';
        $dados['ativo'] = $request->boolean('ativo');
        $dados['limite_dispensa_em_dobro'] = $request->boolean('limite_dispensa_em_dobro'); // art. 75, § 2º
        $dados['pncp_orgao_preparado'] = $request->boolean('pncp_orgao_preparado');

        return $dados;
    }
}
