<x-layouts.app titulo="Integrações">
    <div>
        <h1 class="h1">Integrações</h1>
        <p class="sub">Remessa ao tribunal de contas, dossiê para o SEI e preços do Compras.gov.br</p>
    </div>

    <section class="card"><h2>Remessa ao tribunal de contas</h2>
        <p class="hint">Gera um pacote ZIP com processos, itens, contratos, termos e atas do período, em CSV (separador ";", UTF-8) e um manifesto com o SHA-256 de cada arquivo <span class="lei">art. 169, III</span>. É o layout genérico do NexPlanGov: o tribunal de cada estado tem o seu leiaute, e este pacote serve de base para a conversão. CPF de fornecedor pessoa física sai mascarado.</p>
        <form method="POST" action="{{ route('integracoes.tce') }}" class="form-grid">@csrf
            @if ($orgaos->isNotEmpty())<x-campo nome="orgao_id" rotulo="Órgão" obrigatorio><select id="campo-orgao_id" name="orgao_id" class="input">@foreach ($orgaos as $o)<option value="{{ $o->id }}">{{ $o->sigla }}</option>@endforeach</select></x-campo>@endif
            <x-campo nome="inicio" rotulo="De" tipo="date" :valor="today()->startOfYear()->toDateString()" obrigatorio />
            <x-campo nome="fim" rotulo="Até" tipo="date" :valor="today()->toDateString()" obrigatorio />
            <div class="form-acoes full"><button class="btn btn-p" type="submit"><x-icone nome="download" />Gerar remessa</button></div>
        </form>
    </section>

    <div class="g2">
        <section class="card"><h2>SEI</h2>
            <p class="hint">Em cada processo, informe o número SEI na aba Resumo e baixe o dossiê: capa, peças concluídas, assinaturas e manifesto de integridade. No SEI, inclua cada arquivo como documento externo.</p>
        </section>
        <section class="card"><h2>Compras.gov.br</h2>
            <p class="hint">Na aba Itens e preços do processo, o botão "Buscar preços no Compras.gov.br" traz as compras do último ano com o mesmo código CATMAT/CATSER, para usar como contratações similares <span class="lei">art. 23, § 1º, II</span>.</p>
        </section>
    </div>
</x-layouts.app>
