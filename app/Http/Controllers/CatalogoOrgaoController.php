<?php

namespace App\Http\Controllers;

use App\Licitacao\Regras\DescricaoCatalogo;
use App\Models\ItemCatalogoOrgao;
use App\Rules\CodigoDeCatalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogo do órgão (art. 19, II, e art. 40, § 1º, I): descrição padronizada, atributos, imagem e código nacional,
 * com trava de descrição vaga e de item parecido já cadastrado. Também recebe pela tela o arquivo oficial do
 * CATMAT/CATSER (antes só por comando).
 */
class CatalogoOrgaoController extends Controller
{
    public function index(Request $request): View
    {
        $d = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'situacao' => ['nullable', 'in:ativos,inativos,todos']]);
        $situacao = $d['situacao'] ?? 'ativos';
        $itens = ItemCatalogoOrgao::query()
            ->when(($d['q'] ?? '') !== '', fn ($q) => $q->buscar($d['q']))
            ->when($situacao !== 'todos', fn ($q) => $q->where('ativo', $situacao === 'ativos'))
            ->orderBy('descricao')->paginate(30)->withQueryString();

        return view('catalogo.orgao', ['itens' => $itens, 'q' => $d['q'] ?? '', 'situacao' => $situacao]);
    }

    public function create(): View
    {
        return view('catalogo.orgao-form', ['item' => new ItemCatalogoOrgao(['tipo' => 'M', 'atributos' => []])]);
    }

    public function edit(ItemCatalogoOrgao $item): View
    {
        return view('catalogo.orgao-form', ['item' => $item]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = $this->salvar($request, new ItemCatalogoOrgao(['criado_por' => $request->user()->id]));

        return redirect()->route('catalogo.orgao.index')->with('sucesso', "Item \"{$item->descricao}\" incluído no catálogo do órgão.");
    }

    public function update(Request $request, ItemCatalogoOrgao $item): RedirectResponse
    {
        $this->salvar($request, $item);

        return redirect()->route('catalogo.orgao.index')->with('sucesso', 'Item do catálogo atualizado.');
    }

    /** Inativar não apaga: itens já usados no PCA e nos processos continuam com a descrição da época. */
    public function alternar(Request $request, ItemCatalogoOrgao $item): RedirectResponse
    {
        if ($item->ativo) {
            $d = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']], ['motivo.*' => 'Informe o motivo da inativação (mínimo 10 caracteres).']);
            $item->update(['ativo' => false, 'motivo_inativacao' => trim($d['motivo'])]);

            return back()->with('sucesso', 'Item inativado. Ele deixa de aparecer na busca.');
        }
        $item->update(['ativo' => true, 'motivo_inativacao' => null]);

        return back()->with('sucesso', 'Item reativado.');
    }

    public function imagem(ItemCatalogoOrgao $item): StreamedResponse
    {
        abort_unless($item->imagem_caminho && Storage::disk('local')->exists($item->imagem_caminho), 404);

        return Storage::disk('local')->response($item->imagem_caminho, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** Busca para os formulários do PCA e do processo: só itens ativos do órgão. */
    public function buscar(Request $request): JsonResponse
    {
        $d = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return response()->json(ItemCatalogoOrgao::buscar($d['q'])->where('ativo', true)->orderBy('descricao')->limit(15)->get()
            ->map(fn (ItemCatalogoOrgao $i) => ['id' => $i->id, 'tipo' => $i->tipo, 'codigo' => $i->codigo_catalogo, 'descricao' => $i->descricaoCompleta(), 'unidade' => $i->unidade_fornecimento, 'natureza' => $i->natureza_despesa]));
    }

    /** Arquivo oficial do CATMAT/CATSER enviado pela tela (o mesmo leiaute do comando de importação). */
    public function importarNacional(Request $request): RedirectResponse
    {
        $d = $request->validate(['tipo' => ['required', 'in:M,S'], 'arquivo' => ['required', 'file', 'mimes:csv,txt', 'max:51200']]);
        Artisan::call('nexplangov:catalogo-importar', ['arquivo' => $request->file('arquivo')->getRealPath(), '--tipo' => $d['tipo']]);

        return redirect()->route('catalogo.index')->with('sucesso', trim(Artisan::output()));
    }

    private function salvar(Request $request, ItemCatalogoOrgao $item): ItemCatalogoOrgao
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(ItemCatalogoOrgao::TIPOS))],
            'codigo_catalogo' => ['nullable', 'string', 'max:20', new CodigoDeCatalogo($request->input('tipo'))],
            'descricao' => ['required', 'string', 'max:1000'],
            'unidade_fornecimento' => ['required', 'string', 'max:40'],
            'natureza_despesa' => ['nullable', 'string', 'max:20'],
            'especificacao' => ['nullable', 'string', 'max:5000'],
            'atributos' => ['array', 'max:20'],
            'atributos.*.nome' => ['nullable', 'string', 'max:80'],
            'atributos.*.valor' => ['nullable', 'string', 'max:200'],
            'imagem' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'confirmo_diferente' => ['nullable', 'boolean'],
        ], ['imagem.*' => 'A imagem deve ser PNG, JPG ou WEBP de até 2 MB.']);

        $atributos = array_values(array_filter(array_map(fn ($a) => ['nome' => trim((string) ($a['nome'] ?? '')), 'valor' => trim((string) ($a['valor'] ?? ''))], $d['atributos'] ?? []),
            fn ($a) => $a['nome'].$a['valor'] !== ''));
        if ($m = DescricaoCatalogo::motivos($d['tipo'], $d['descricao'], $atributos)) {
            throw ValidationException::withMessages(['descricao' => implode(' ', $m)]);
        }

        $normalizada = DescricaoCatalogo::normalizar($d['descricao']);
        $mesmos = ItemCatalogoOrgao::where('tipo', $d['tipo'])->whereKeyNot($item->id)->get(['id', 'descricao', 'descricao_normalizada']);
        if ($igual = $mesmos->firstWhere('descricao_normalizada', $normalizada)) {
            throw ValidationException::withMessages(['descricao' => "Este item já está no catálogo do órgão: \"{$igual->descricao}\"."]);
        }
        $parecidos = DescricaoCatalogo::parecidos($d['descricao'], $mesmos->pluck('descricao', 'id')->all());
        if ($parecidos && ! $request->boolean('confirmo_diferente')) {
            $lista = collect($parecidos)->take(3)->map(fn ($s, $id) => '"'.$mesmos->firstWhere('id', $id)->descricao.'" ('.round($s * 100).'% parecido)')->implode('; ');
            throw ValidationException::withMessages(['descricao' => "Já existe item parecido: {$lista}. Use o existente ou marque que este é um item diferente."]);
        }

        $item->fill([
            'tipo' => $d['tipo'], 'codigo_catalogo' => $d['codigo_catalogo'] ?? null, 'descricao' => trim($d['descricao']), 'unidade_fornecimento' => trim($d['unidade_fornecimento']),
            'natureza_despesa' => $d['natureza_despesa'] ?? null, 'especificacao' => $d['especificacao'] ?? null, 'atributos' => $atributos,
        ]);
        if ($request->hasFile('imagem')) {
            $arquivo = $request->file('imagem');
            $item->imagem_caminho = $arquivo->store('catalogo/'.$request->user()->orgao_id, 'local');
            $item->imagem_hash = hash_file('sha256', $arquivo->getRealPath());
        }
        $item->save();

        return $item;
    }
}
