<?php

namespace App\Http\Controllers;

use App\Licitacao\Ia\AssistenteIa;
use App\Licitacao\Ia\IaIndisponivel;
use App\Licitacao\Regras\MatrizRiscos;
use App\Licitacao\RegraViolada;
use App\Models\Processo;
use App\Models\RiscoProcesso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Matriz de riscos estruturada do processo (art. 6º, XXVII, art. 18, X, art. 22 e art. 103), com proposta inicial
 * da NexMind IA que o servidor aceita linha a linha, e preenchimento da peça "Mapa de riscos" a partir da matriz.
 */
class RiscoProcessoController extends Controller
{
    public function store(Request $request, Processo $processo): RedirectResponse
    {
        $this->exigirEdicao($processo);
        $d = $this->dados($request);
        RiscoProcesso::create($d + ['orgao_id' => $processo->orgao_id, 'processo_id' => $processo->id, 'ordem' => $this->proximaOrdem($processo), 'origem' => 'manual', 'criado_por' => $request->user()->id]);

        return $this->voltar($processo, 'Risco incluído na matriz.');
    }

    public function update(Request $request, Processo $processo, RiscoProcesso $risco): RedirectResponse
    {
        abort_unless($risco->processo_id === $processo->id, 404);
        $this->exigirEdicao($processo);
        $risco->update($this->dados($request));

        return $this->voltar($processo, 'Risco atualizado.');
    }

    public function destroy(Processo $processo, RiscoProcesso $risco): RedirectResponse
    {
        abort_unless($risco->processo_id === $processo->id, 404);
        $this->exigirEdicao($processo);
        $risco->delete();

        return $this->voltar($processo, 'Risco retirado da matriz.');
    }

    /** A IA propõe; a proposta fica na sessão até o servidor escolher o que aceitar. */
    public function sugerir(Request $request, Processo $processo, AssistenteIa $ia): RedirectResponse
    {
        $this->exigirEdicao($processo);
        if (AssistenteIa::limiteAtingido($request->user())) {
            return $this->voltar($processo, null, 'Você chegou ao limite diário de uso da IA. Amanhã o limite renova.');
        }
        try {
            $sugestoes = $ia->sugerirRiscos($processo, $request->user());
        } catch (RegraViolada $e) {
            return $this->voltar($processo, null, implode(' ', $e->motivos));
        } catch (IaIndisponivel $e) {
            return $this->voltar($processo, null, $e->getMessage());
        }
        if (! $sugestoes) {
            return $this->voltar($processo, null, 'A IA não trouxe nenhum risco aproveitável. Tente de novo ou registre os riscos manualmente.');
        }
        session()->put("riscos_ia.{$processo->id}", $sugestoes);

        return $this->voltar($processo, count($sugestoes).' risco(s) proposto(s) pela IA. Revise e marque os que entram na matriz.');
    }

    public function aceitar(Request $request, Processo $processo): RedirectResponse
    {
        $this->exigirEdicao($processo);
        $sugestoes = session()->pull("riscos_ia.{$processo->id}", []);
        $escolhidas = array_map('intval', (array) $request->input('escolhidas', []));
        $n = 0;
        foreach ($escolhidas as $k) {
            if (isset($sugestoes[$k]) && MatrizRiscos::motivos($sugestoes[$k]) === []) {
                RiscoProcesso::create($sugestoes[$k] + ['orgao_id' => $processo->orgao_id, 'processo_id' => $processo->id, 'ordem' => $this->proximaOrdem($processo), 'origem' => 'ia', 'criado_por' => $request->user()->id]);
                $n++;
            }
        }

        return $this->voltar($processo, $n ? "{$n} risco(s) aceito(s). Ajuste probabilidade, impacto e tratamento se precisar." : 'Nenhum risco aceito.');
    }

    /** Leva a matriz para a peça "Mapa de riscos" como rascunho; quem elabora revisa e salva. */
    public function peca(Processo $processo): RedirectResponse
    {
        $riscos = $processo->riscos;
        if ($riscos->isEmpty()) {
            return $this->voltar($processo, null, 'Inclua ao menos um risco na matriz antes de preencher a peça.');
        }

        return redirect()->route('documentos.edit', [$processo, 'mapa_riscos'])
            ->withInput(['conteudo' => MatrizRiscos::textoDaPeca($riscos->map(fn (RiscoProcesso $r) => $r->only(['risco', 'causa', 'consequencia', 'probabilidade', 'impacto', 'alocacao', 'tratamento', 'responsavel']))->all())])
            ->with('sucesso', 'Peça preenchida com a matriz de riscos. Revise e salve.');
    }

    private function dados(Request $request): array
    {
        $d = $request->validate([
            'risco' => ['required', 'string', 'max:500'], 'causa' => ['nullable', 'string', 'max:1000'], 'consequencia' => ['nullable', 'string', 'max:1000'],
            'probabilidade' => ['required', 'integer', 'between:1,5'], 'impacto' => ['required', 'integer', 'between:1,5'],
            'alocacao' => ['required', Rule::in(array_keys(MatrizRiscos::ALOCACOES))], 'tratamento' => ['nullable', 'string', 'max:5000'], 'responsavel' => ['nullable', 'string', 'max:200'],
        ]);
        if ($m = MatrizRiscos::motivos($d)) {
            throw ValidationException::withMessages(['risco' => implode(' ', $m)]);
        }

        return ['probabilidade' => (int) $d['probabilidade'], 'impacto' => (int) $d['impacto']] + $d;
    }

    private function exigirEdicao(Processo $processo): void
    {
        abort_unless($processo->editavel(), 403, 'O processo não está mais em instrução.');
    }

    private function proximaOrdem(Processo $processo): int
    {
        return ((int) RiscoProcesso::where('processo_id', $processo->id)->max('ordem')) + 1;
    }

    private function voltar(Processo $processo, ?string $sucesso, ?string $erro = null): RedirectResponse
    {
        $r = redirect()->route('processos.show', [$processo, 'aba' => 'riscos']);

        return $erro ? $r->with('erro', $erro) : $r->with('sucesso', $sucesso);
    }
}
