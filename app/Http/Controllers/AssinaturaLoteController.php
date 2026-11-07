<?php

namespace App\Http\Controllers;

use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\Fluxo\FluxoDocumento;
use App\Licitacao\RegraViolada;
use App\Licitacao\TiposDocumento;
use App\Models\DocumentoProcesso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** Assinatura em lote: todas as peças que esperam a assinatura da pessoa, com uma única confirmação de senha. */
class AssinaturaLoteController extends Controller
{
    public function __construct(private FluxoDocumento $fluxo, private Assinaturas $assinaturas) {}

    public function index(Request $request): View
    {
        return view('assinaturas.pendentes', ['pendentes' => $this->pendentes($request), 'tipos' => TiposDocumento::todos()]);
    }

    public function assinar(Request $request): RedirectResponse
    {
        $d = $request->validate(['documentos' => ['required', 'array', 'min:1', 'max:50'], 'documentos.*' => ['integer'], 'senha' => ['required', 'string', 'max:255']],
            ['documentos.required' => 'Marque ao menos uma peça.', 'senha.required' => 'Digite a sua senha para assinar.']);

        // A senha é conferida uma vez; cada peça ainda passa por todas as regras da assinatura individual.
        if (! Hash::check($d['senha'], $request->user()->password)) {
            return back()->with('erro', 'Senha incorreta. Nenhuma peça foi assinada.');
        }

        $alvo = $this->pendentes($request)->whereIn('id', array_map('intval', $d['documentos']));
        $ok = 0;
        $falhas = [];
        foreach ($alvo as $doc) {
            try {
                $this->assinaturas->assinar($doc, $request->user(), $d['senha'], $request->ip());
                $ok++;
            } catch (RegraViolada $e) {
                $falhas[] = "Processo {$doc->processo->identificacao()}, ".(TiposDocumento::get($doc->tipo)['rotulo'] ?? $doc->tipo).': '.implode(' ', $e->motivos);
            }
        }

        $r = back()->with('sucesso', "{$ok} peça(s) assinada(s).");

        return $falhas ? $r->with('erro', 'Algumas peças não foram assinadas.')->with('motivos', $falhas) : $r;
    }

    /** @return Collection<int, DocumentoProcesso> */
    private function pendentes(Request $request): Collection
    {
        $u = $request->user();

        return DocumentoProcesso::with(['processo', 'assinaturas'])->where('situacao', 'aguardando_assinatura')->orderBy('updated_at')->get()
            ->filter(fn (DocumentoProcesso $d) => $this->fluxo->podeAssinar($u, $d) && ! $d->assinaturas->contains('user_id', $u->id))
            ->values();
    }
}
