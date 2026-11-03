<?php

namespace App\Http\Controllers;

use App\Licitacao\Contratos\Carona;
use App\Licitacao\RegraViolada;
use App\Models\Ata;
use App\Models\AtaAdesao;
use App\Rules\Cnpj;
use App\Support\Documento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Páginas públicas da carona (art. 86, § 2º): o órgão de fora, sem conta no sistema, encontra a ata vigente,
 * pede a adesão com ofício e acompanha pelo protocolo. O fornecedor responde pelo link assinado e qualquer
 * pessoa confere a autorização pelo QR Code.
 */
class CaronaController extends Controller
{
    public function __construct(private Carona $carona) {}

    public function index(Request $request): View
    {
        $busca = trim((string) $request->query('q'));
        $atas = Ata::with(['orgao', 'fornecedor', 'processo'])->where('situacao', 'vigente')->whereDate('vigencia_fim', '>=', today())
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w->whereHas('processo', fn ($p) => $p->where('objeto', 'ilike', "%{$busca}%"))
                ->orWhereHas('orgao', fn ($o) => $o->where('nome', 'ilike', "%{$busca}%"))->orWhereHas('itens.item', fn ($i) => $i->where('descricao', 'ilike', "%{$busca}%"))))
            ->orderByDesc('ano')->orderByDesc('numero')->paginate(20)->withQueryString();

        return view('carona.index', ['atas' => $atas, 'busca' => $busca]);
    }

    public function ata(Ata $ata): View
    {
        abort_unless($ata->vigente(), 404);
        $ata->load(['orgao', 'fornecedor', 'processo', 'itens.item']);

        return view('carona.ata', ['ata' => $ata]);
    }

    public function pedir(Request $request, Ata $ata): RedirectResponse
    {
        abort_unless($ata->vigente(), 404);

        // Campo-isca: robô preenche, pessoa não vê.
        if ($request->filled('site')) {
            return redirect()->route('carona.consulta')->with('sucesso', 'Pedido recebido.');
        }

        $request->merge(['quantidade' => decimal_br($request->input('quantidade'))]);
        $d = $request->validate([
            'item_id' => ['required', 'integer'], 'aderente_nome' => ['required', 'string', 'max:200'], 'aderente_cnpj' => ['required', new Cnpj],
            'aderente_esfera' => ['required', Rule::in(array_keys(AtaAdesao::ESFERAS))], 'aderente_uf' => ['required', 'string', 'size:2'], 'aderente_municipio' => ['nullable', 'string', 'max:120'],
            'solicitante_nome' => ['required', 'string', 'max:160'], 'solicitante_cargo' => ['required', 'string', 'max:120'], 'solicitante_email' => ['required', 'email', 'max:160'],
            'quantidade' => ['required', 'numeric', 'gt:0'], 'justificativa' => ['required', 'string', 'max:2000'], 'vantagem' => ['required', 'string', 'max:2000'],
            'oficio' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);
        $item = $ata->itens()->findOrFail($d['item_id']);
        $d['aderente_uf'] = strtoupper($d['aderente_uf']);

        try {
            $a = $this->carona->solicitar($item, $d, null, $request->file('oficio'), 'publico');
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível registrar o pedido.')->with('motivos', $e->motivos);
        }

        return redirect()->route('carona.consulta')->with('sucesso', "Pedido recebido. Guarde o protocolo {$a->protocolo}: com ele e o CNPJ do órgão você acompanha a resposta.")
            ->with('protocolo', $a->protocolo);
    }

    public function consulta(): View
    {
        return view('carona.consulta', ['adesao' => null]);
    }

    public function consultar(Request $request): View|RedirectResponse
    {
        $d = $request->validate(['protocolo' => ['required', 'string', 'max:20'], 'cnpj' => ['required', 'string', 'max:18']]);
        $a = AtaAdesao::with(['ataItem.item', 'ataItem.ata.orgao', 'ataItem.ata.fornecedor'])->where('protocolo', strtoupper(trim($d['protocolo'])))->first();

        // A mesma mensagem para protocolo inexistente e CNPJ errado: não revela o que existe.
        if (! $a || Documento::digitos($a->aderente_cnpj) !== Documento::digitos($d['cnpj'])) {
            return back()->withInput()->with('erro', 'Nenhum pedido encontrado com esse protocolo e CNPJ.');
        }

        return view('carona.consulta', ['adesao' => $a]);
    }

    /** Página do fornecedor: aberta pelo link assinado, sem conta no sistema. */
    public function aceite(AtaAdesao $adesao): View
    {
        $adesao->load(['ataItem.item', 'ataItem.ata.orgao', 'ataItem.ata.fornecedor']);

        return view('carona.aceite', ['a' => $adesao]);
    }

    public function responderAceite(Request $request, AtaAdesao $adesao): RedirectResponse
    {
        $d = $request->validate(['resposta' => ['required', 'in:aceito,recusado'], 'representante' => ['required', 'string', 'max:160'], 'obs' => ['nullable', 'string', 'max:500'], 'ciente' => ['accepted']]);

        try {
            $this->carona->registrarAceite($adesao, $d['resposta'] === 'aceito', $d['representante'], $d['obs'] ?? null, 'link');
        } catch (RegraViolada $e) {
            return back()->withInput()->with('erro', 'Não foi possível registrar a resposta.')->with('motivos', $e->motivos);
        }

        return back()->with('sucesso', 'Resposta registrada. O órgão gerenciador decide a autorização.');
    }

    public function verificar(string $codigo): View
    {
        $a = AtaAdesao::with(['ataItem.item', 'ataItem.ata.orgao', 'ataItem.ata.fornecedor'])->where('codigo_verificacao', strtoupper($codigo))->first();

        return view('carona.verificar', ['a' => $a, 'codigo' => $codigo]);
    }

    public function autorizacao(string $codigo): StreamedResponse
    {
        $a = AtaAdesao::where('codigo_verificacao', strtoupper($codigo))->whereNotNull('autorizacao_caminho')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($a->autorizacao_caminho), 404);

        return Storage::disk('local')->download($a->autorizacao_caminho, "autorizacao-{$a->protocolo}.pdf");
    }
}
