<?php

namespace App\Http\Controllers;

use App\Licitacao\TiposDocumento;
use App\Models\ModeloDocumento;
use App\Models\ModeloDocumentoVersao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Biblioteca de modelos do órgão: editáveis, com versões e campos dinâmicos. */
class ModeloController extends Controller
{
    public function index(Request $request): View
    {
        $tipo = $request->query('tipo');
        $modelos = ModeloDocumento::query()
            ->when($tipo && array_key_exists($tipo, TiposDocumento::todos()), fn ($q) => $q->where('tipo', $tipo))
            ->orderBy('tipo')->orderBy('titulo')->get();

        return view('modelos.index', ['modelos' => $modelos, 'tipos' => TiposDocumento::todos(), 'tipo' => $tipo]);
    }

    public function create(Request $request): View
    {
        $tipo = array_key_exists((string) $request->query('tipo'), TiposDocumento::todos()) ? $request->query('tipo') : 'termo_referencia';

        return view('modelos.form', ['modelo' => new ModeloDocumento(['tipo' => $tipo, 'aplicacao' => 'todos', 'ativo' => true, 'conteudo' => []]), 'tipos' => TiposDocumento::todos()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $orgaoId = $request->user()->orgao_id;
        abort_unless($orgaoId, 422);
        $d = $this->validar($request);
        $modelo = ModeloDocumento::create($d + ['orgao_id' => $orgaoId, 'codigo' => 'proprio-'.Str::lower(Str::random(8)), 'versao' => 1, 'padrao_nexmind' => false]);

        return redirect()->route('modelos.edit', $modelo)->with('sucesso', 'Modelo criado.');
    }

    public function edit(ModeloDocumento $modelo): View
    {
        return view('modelos.form', ['modelo' => $modelo->load('versoes.autor'), 'tipos' => TiposDocumento::todos()]);
    }

    public function update(Request $request, ModeloDocumento $modelo): RedirectResponse
    {
        $d = $this->validar($request, $modelo->tipo);
        $this->guardarVersao($modelo); // guarda o texto anterior antes de trocar
        $modelo->update($d + ['versao' => $modelo->versao + 1]);

        return redirect()->route('modelos.edit', $modelo)->with('sucesso', "Modelo salvo (versão {$modelo->versao}).");
    }

    public function duplicar(ModeloDocumento $modelo): RedirectResponse
    {
        $copia = $modelo->replicate();
        $copia->fill(['titulo' => Str::limit($modelo->titulo, 140, '').' (cópia)', 'codigo' => 'proprio-'.Str::lower(Str::random(8)), 'versao' => 1, 'padrao_nexmind' => false])->save();

        return redirect()->route('modelos.edit', $copia)->with('sucesso', 'Cópia criada. Ela é sua: edite à vontade.');
    }

    public function alternar(ModeloDocumento $modelo): RedirectResponse
    {
        $modelo->update(['ativo' => ! $modelo->ativo]);

        return back()->with('sucesso', $modelo->ativo ? 'Modelo ativado.' : 'Modelo desativado: não aparece mais para escolha.');
    }

    public function restaurar(ModeloDocumento $modelo, int $versao): RedirectResponse
    {
        $antiga = $modelo->versoes()->where('versao', $versao)->firstOrFail();
        $this->guardarVersao($modelo);
        $modelo->update(['conteudo' => $antiga->conteudo, 'titulo' => $antiga->titulo, 'versao' => $modelo->versao + 1]);

        return redirect()->route('modelos.edit', $modelo)->with('sucesso', "Texto da versão {$versao} restaurado como versão {$modelo->versao}.");
    }

    private function validar(Request $request, ?string $tipoFixo = null): array
    {
        $tipo = $tipoFixo ?? $request->input('tipo');
        abort_unless(array_key_exists((string) $tipo, TiposDocumento::todos()), 422);
        $campos = TiposDocumento::get($tipo)['campos'];

        $regras = [
            'titulo' => ['required', 'string', 'max:160'],
            'descricao' => ['nullable', 'string', 'max:1000'],
            'aplicacao' => ['required', Rule::in(['todos', 'licitacao', 'direta'])],
            'categoria' => ['nullable', 'integer', 'between:1,10'],
        ];
        foreach ($campos as $c => $_) {
            $regras["conteudo.$c"] = ['nullable', 'string', 'max:20000'];
        }

        $d = $request->validate($regras);
        $conteudo = [];
        foreach ($campos as $c => $_) {
            $conteudo[$c] = trim((string) ($d['conteudo'][$c] ?? ''));
        }
        unset($d['conteudo']);

        return $d + ['tipo' => $tipo, 'conteudo' => $conteudo, 'ativo' => $request->boolean('ativo')];
    }

    /** Guarda o texto que está valendo agora, antes de ser trocado. */
    private function guardarVersao(ModeloDocumento $modelo): void
    {
        ModeloDocumentoVersao::firstOrCreate(
            ['modelo_id' => $modelo->id, 'versao' => $modelo->versao],
            ['orgao_id' => $modelo->orgao_id, 'titulo' => $modelo->titulo, 'conteudo' => $modelo->conteudo, 'user_id' => auth()->id()],
        );
    }
}
