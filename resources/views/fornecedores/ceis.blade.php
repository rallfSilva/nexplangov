<x-layouts.app :titulo="'CEIS e CNEP · '.$fornecedor->razao_social">
    <div>
        <a href="{{ route('fornecedores.index') }}" class="small" style="font-weight:600;display:inline-flex;gap:4px;align-items:center"><x-icone nome="voltar" tamanho="16" />Fornecedores</a>
        <h1 class="h1" style="margin-top:8px">CEIS e CNEP</h1>
        <p class="sub">{{ $fornecedor->razao_social }} · {{ $fornecedor->documento }}</p>
    </div>
    <div class="aviso-lei">Antes de adjudicar, contratar e emitir ordem de fornecimento, o sistema confere o fornecedor no Cadastro Nacional de Empresas Inidôneas e Suspensas (CEIS) e no Cadastro Nacional de Empresas Punidas (CNEP). Inidoneidade bloqueia sempre. Impedimento e suspensão bloqueiam até a autoridade registrar se a sanção alcança este órgão <span class="lei">art. 156, §§ 4º e 5º, e art. 161</span></div>

    @unless ($habilitada)
        <div class="flash flash-aviso" role="status">A consulta automática está desligada: falta a chave da API do Portal da Transparência no .env (<code>TRANSPARENCIA_CHAVE</code>).</div>
    @endunless

    @can('fornecedores.gerenciar')
        @if ($habilitada)
            <form method="POST" action="{{ route('fornecedores.ceis', $fornecedor) }}">@csrf<button class="btn btn-p" type="submit">Consultar agora</button></form>
        @endif
    @endcan

    @forelse ($consultas as $c)
        @php($vigentes = $c->vigentes())
        <section class="card">
            <div class="row">
                <h2>Consulta de {{ $c->consultado_em->format('d/m/Y H:i') }}</h2>
                <span class="b {{ $c->situacao === 'regular' ? 'b-ok' : ($c->situacao === 'sancionado' ? 'b-err' : 'b-warn') }}">{{ \App\Models\ConsultaImpedimento::SITUACOES[$c->situacao] }}</span>
            </div>
            <p class="hint">{{ $c->usuario ? 'Feita por '.$c->usuario->name : 'Feita automaticamente pelo sistema' }}@if ($c->hash_resposta) · SHA-256 da resposta {{ \Illuminate\Support\Str::limit($c->hash_resposta, 16, '…') }}@endif</p>
            @if ($c->erro)<p class="small" style="margin-top:8px">{{ $c->erro }}</p>@endif
            @if ($c->registros)
                <div class="tbl-wrap" style="margin-top:12px">
                    <table class="tbl">
                        <thead><tr><th scope="col">Cadastro</th><th scope="col">Sanção</th><th scope="col">Órgão sancionador</th><th scope="col">Período</th><th scope="col">Situação hoje</th></tr></thead>
                        <tbody>
                            @foreach ($c->registros as $r)
                                <tr>
                                    <td>{{ strtoupper($r['cadastro']) }}</td>
                                    <td>{{ $r['tipo'] }}</td>
                                    <td>{{ $r['orgao'] }}</td>
                                    <td>{{ $r['inicio'] ? \Illuminate\Support\Carbon::parse($r['inicio'])->format('d/m/Y') : '—' }} a {{ $r['fim'] ? \Illuminate\Support\Carbon::parse($r['fim'])->format('d/m/Y') : 'indeterminado' }}</td>
                                    <td>{{ in_array($r, $vigentes, true) ? (\App\Licitacao\Regras\ImpedimentoCadastral::ehImpeditiva($r) ? 'Vigente, impeditiva' : 'Vigente, não impede') : 'Encerrada' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($c->ressalva_em)
                <p class="small" style="margin-top:12px;line-height:1.6"><b>Análise de abrangência</b> por {{ $c->autorRessalva?->name }} em {{ $c->ressalva_em->format('d/m/Y H:i') }} <span class="lei">art. 156, § 4º</span><br>{{ $c->ressalva_justificativa }}</p>
            @elseif ($loop->first && \App\Licitacao\Regras\ImpedimentoCadastral::podeRessalvar($vigentes))
                @can('processos.autorizar')
                    <form method="POST" action="{{ route('fornecedores.ceis.ressalva', [$fornecedor, $c]) }}" class="form-grid" style="margin-top:14px" novalidate>@csrf
                        <x-campo nome="justificativa" rotulo="Análise de abrangência: por que a sanção não alcança este órgão" obrigatorio classe="full">
                            <textarea id="campo-justificativa" name="justificativa" class="input" rows="3">{{ old('justificativa') }}</textarea>
                        </x-campo>
                        <div class="campo"><button class="btn btn-p" type="submit">Registrar análise</button></div>
                    </form>
                @else
                    <p class="small muted" style="margin-top:12px">Há sanção vigente que impede contratar. Só a autoridade competente pode registrar a análise de abrangência.</p>
                @endcan
            @endif
        </section>
    @empty
        <section class="card"><p class="muted">Nenhuma consulta feita ainda.</p></section>
    @endforelse
</x-layouts.app>
