@php($geren = auth()->user()->can('contratos.gerenciar'))
@php($pag = auth()->user()->can('pagamentos.gerenciar'))
@php($fontes = \App\Models\Liquidacao::distinct()->orderBy('fonte_recurso')->pluck('fonte_recurso'))
@php($catSug = (new \App\Licitacao\Regras\OrdemCronologica)->categoriaSugerida($contrato->categoria))
<section class="card"><h2>Medições e pagamentos <span class="lei">arts. 140 e 141</span></h2>
    <p class="hint">O fiscal mede o período, o gestor aprova (nunca quem mediu), o setor financeiro liquida com a nota fiscal e o crédito entra na <a href="{{ route('pagamentos.index') }}">ordem cronológica</a>. Medido até agora: {{ reais($contrato->medicoes->whereIn('situacao', ['pendente', 'aprovada'])->sum('valor')) }} de {{ reais($contrato->valorAtualizadoTotal()) }}.</p>
    @if ($contrato->medicoes->isNotEmpty())
        <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Nº</th><th>Período</th><th class="num">Valor</th><th>Situação</th><th></th></tr></thead><tbody>
            @foreach ($contrato->medicoes as $med)
                <tr><td><b>{{ $med->numero }}</b></td><td>{{ $med->periodo_inicio->format('d/m/Y') }} a {{ $med->periodo_fim->format('d/m/Y') }}<div class="small muted">{{ \Illuminate\Support\Str::limit($med->descricao, 120) }}</div></td>
                    <td class="num valor">{{ reais($med->valor) }}</td>
                    <td>@if ($med->liquidacao)<span class="b {{ $med->liquidacao->situacao === 'pago' ? 'b-ok' : 'b-info' }}">{{ $med->liquidacao->situacao === 'pago' ? 'Paga em '.$med->liquidacao->pago_em->format('d/m/Y') : ($med->liquidacao->situacao === 'cancelado' ? 'Liquidação cancelada' : 'Liquidada · na fila') }}</span>
                        @else<span class="b {{ $med->situacao === 'aprovada' ? 'b-ok' : ($med->situacao === 'recusada' ? 'b-err' : 'b-info') }}">{{ ['pendente' => 'Aguardando o gestor', 'aprovada' => 'Aprovada', 'recusada' => 'Recusada'][$med->situacao] }}</span>@endif
                        @if ($med->motivo_recusa)<div class="small muted">{{ $med->motivo_recusa }}</div>@endif</td>
                    <td class="acoes">
                        @if ($geren && $med->situacao === 'pendente')
                            <form method="POST" action="{{ route('medicoes.decidir', [$contrato, $med]) }}" class="btns">@csrf<input name="motivo" class="input" style="width:160px" placeholder="Motivo (se recusar)" aria-label="Motivo da recusa da medição {{ $med->numero }}"><button class="btn btn-s btn-sm" name="acao" value="aprovar">Aprovar</button><button class="btn btn-s btn-sm" name="acao" value="recusar">Recusar</button></form>
                        @endif
                    </td></tr>
                @if ($pag && $med->situacao === 'aprovada' && ! $med->liquidacao)
                    <tr><td colspan="5"><details><summary class="small" style="cursor:pointer;font-weight:600">Liquidar a medição {{ $med->numero }}</summary>
                        @include('pagamentos._liquidar', ['acao' => route('medicoes.liquidar', $med), 'p' => 'med'.$med->id, 'categoria' => $catSug, 'valor' => $med->valor, 'fontes' => $fontes])</details></td></tr>
                @endif
            @endforeach
        </tbody></table></div>
    @endif
    @if ($geren)
        <details style="margin-top:10px"><summary class="btn btn-s btn-sm" style="list-style:none">+ Registrar medição</summary>
            <form method="POST" action="{{ route('medicoes.store', $contrato) }}" class="form-grid" style="margin-top:12px">@csrf
                <x-campo nome="periodo_inicio" rotulo="Início do período" tipo="date" obrigatorio />
                <x-campo nome="periodo_fim" rotulo="Fim do período" tipo="date" obrigatorio />
                <x-campo nome="valor" rotulo="Valor medido" obrigatorio inputmode="decimal" />
                <div class="campo full"><label for="med-descricao">O que foi executado e medido</label><textarea id="med-descricao" name="descricao" class="input" rows="3" required></textarea></div>
                <div class="form-acoes full"><button class="btn btn-p" type="submit">Registrar medição</button></div>
            </form>
        </details>
    @endif
</section>
