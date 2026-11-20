<?php

namespace App\Licitacao\Ia;

use App\Licitacao\Regras\CotacaoAutomatica;
use App\Licitacao\Regras\MatrizRiscos;
use App\Licitacao\RegraViolada;
use App\Licitacao\TiposDocumento;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\User;
use App\Models\UsoIa;
use App\Support\Parametros;
use Illuminate\Support\Str;

/**
 * NexMind IA: sugere texto para um campo da peça e revisa a peça frente à Lei 14.133/2021.
 * O texto é só sugestão: quem assina a peça responde por ela. Não redige parecer jurídico nem
 * decisão da autoridade. Nada é gravado sem o usuário revisar e salvar.
 */
final class AssistenteIa
{
    /** Peças que a IA não redige: opinião jurídica e decisão são atos pessoais de quem tem a competência. */
    public const BLOQUEADAS = ['parecer_juridico', 'autorizacao'];

    private const SISTEMA = 'Você apoia servidores públicos brasileiros na instrução de contratações pela Lei nº 14.133/2021, a única lei aplicável. '
        .'Escreva em português do Brasil, em linguagem simples e objetiva, no registro de um documento oficial. '
        .'Cite o artigo da Lei 14.133/2021 quando a regra vier dela, e só cite artigos que você tem certeza que existem. '
        .'Não invente dados: valores, quantidades, datas e nomes que não estiverem no contexto devem aparecer entre colchetes para o servidor completar, como [valor estimado]. '
        .'Responda só com o texto pedido, sem preâmbulo.';

    public function __construct(private ClienteIa $cliente) {}

    public static function habilitada(): bool
    {
        return (bool) config('nexplangov.ia.habilitada');
    }

    public function sugerir(Processo $p, string $tipo, string $campo, array $conteudo, User $u): string
    {
        $def = $this->exigir($tipo);
        if (! isset($def['campos'][$campo])) {
            throw new RegraViolada(['Campo desconhecido nesta peça.']);
        }

        $pedido = $this->contexto($p, $def, $conteudo)
            ."\n\nEscreva uma proposta de texto para o campo \"{$def['campos'][$campo]['rotulo']}\" da peça \"{$def['rotulo']}\" ({$def['base']}). "
            .(($conteudo[$campo] ?? '') !== '' ? 'Melhore o rascunho atual do campo, mantendo os fatos dele.' : 'O campo está vazio.');

        return $this->chamar($p, $tipo, 'sugerir', $pedido, $u);
    }

    public function revisar(Processo $p, string $tipo, array $conteudo, User $u): string
    {
        $def = $this->exigir($tipo);
        $pedido = $this->contexto($p, $def, $conteudo)
            ."\n\nRevise a peça \"{$def['rotulo']}\" ({$def['base']}) frente à Lei 14.133/2021. Liste, em tópicos curtos, o que falta, o que está vago e o que pode gerar questionamento do controle, "
            .'citando o artigo em cada ponto. Termine com uma linha \"Pontos fortes:\" e o que está bom. Não reescreva a peça.';

        return $this->chamar($p, $tipo, 'revisar', $pedido, $u);
    }

    /**
     * Proposta inicial da matriz de riscos a partir do objeto, dos itens, do ETP e do TR. Volta só as linhas que passam
     * nas regras da matriz; nada é gravado sem o servidor aceitar (art. 22).
     *
     * @return list<array<string, mixed>>
     */
    public function sugerirRiscos(Processo $p, User $u): array
    {
        $this->exigirHabilitada();
        $linhas = ["Processo {$p->identificacao()}. Objeto: {$p->objeto}", 'Modalidade: '.$p->modalidadeEnum()->rotulo().'. Categoria: '.$p->categoriaEnum()->rotulo().'.'];
        foreach ($p->itens()->limit(30)->get() as $i) {
            $linhas[] = "Item {$i->numero}: {$i->descricao} ({$i->quantidade} {$i->unidade_medida}).";
        }
        foreach ($p->documentos()->whereIn('tipo', ['etp', 'termo_referencia'])->orderByDesc('versao')->get()->unique('tipo') as $d) {
            $linhas[] = "\nTrechos de ".(TiposDocumento::get($d->tipo)['rotulo'] ?? $d->tipo).':';
            foreach (($d->conteudo ?? []) as $v) {
                if (is_string($v) && trim($v) !== '') {
                    $linhas[] = '- '.Str::limit(trim($v), 1200);
                }
            }
        }
        foreach ($p->riscos as $r) {
            $linhas[] = "Risco já registrado: {$r->risco}.";
        }
        $pedido = Anonimizador::limpar(implode("\n", $linhas))
            ."\n\nProponha até 8 riscos novos desta contratação (art. 18, X, e art. 22). Responda só com um array JSON, sem texto fora dele, em que cada elemento tem as chaves "
            .'"risco", "causa", "consequencia", "probabilidade" (inteiro de 1 a 5), "impacto" (inteiro de 1 a 5), "alocacao" ("contratante", "contratado" ou "compartilhado", art. 103), '
            .'"tratamento" (prevenção ou contingência) e "responsavel" (função, nunca nome de pessoa).';

        return MatrizRiscos::lerSugestao($this->chamarSem($p->orgao_id, $p->id, 'mapa_riscos', 'riscos', $pedido, $u));
    }

    /** Recomendações em outras etapas (PCA, pesquisa de preços, gestão do contrato). Texto de apoio, sem decidir nada. */
    public function recomendar(string $etapa, string $contexto, int $orgaoId, ?int $processoId, User $u): string
    {
        $this->exigirHabilitada();
        $foco = [
            'pca' => 'o Plano de Contratações Anual (art. 12, VII): o que agrupar, o que falta planejar, riscos de fracionamento (art. 75, § 1º) e de falta de orçamento (art. 18 e art. 150)',
            'precos' => 'a pesquisa de preços (art. 23): fontes que faltam, cotações discrepantes ou vencidas e se o método escolhido é adequado. Não decida descartar preço: aponte o que o servidor deve conferir',
            'contrato' => 'a gestão e a fiscalização do contrato (arts. 104, 117, 124 a 136 e 140): prazos, limites de acréscimo, ocorrências pendentes e providências do gestor e do fiscal',
        ][$etapa] ?? throw new RegraViolada(['Etapa desconhecida.']);
        $pedido = Anonimizador::limpar($contexto)."\n\nFaça recomendações práticas sobre {$foco}. Liste em tópicos curtos, do mais para o menos urgente, citando o artigo em cada ponto. Não reescreva documentos.";

        return $this->chamarSem($orgaoId, $processoId, $etapa, 'recomendar', $pedido, $u);
    }

    /**
     * Cotação automática, passo 1: entre os itens do catálogo parecidos com a descrição, qual é o código do item
     * (art. 19, II) e com que palavras procurar compras parecidas no PNCP. Só vale código que está na lista.
     *
     * @param  list<array{codigo: string, descricao: string}>  $candidatos
     * @return array{codigo: ?string, termos: ?string}
     */
    public function cotarCatalogo(ItemProcesso $item, array $candidatos, User $u): array
    {
        $this->exigirHabilitada();
        $linhas = ['Item a cotar ('.($item->material_servico === 'S' ? 'serviço, catálogo CATSER' : 'material, catálogo CATMAT')."): {$item->descricao}. Unidade: {$item->unidade_medida}."];
        $linhas[] = $candidatos === [] ? 'Nenhum item do catálogo foi encontrado.' : 'Itens do catálogo encontrados:';
        foreach ($candidatos as $c) {
            $linhas[] = "- {$c['codigo']}: {$c['descricao']}";
        }
        $pedido = Anonimizador::limpar(implode("\n", $linhas))
            ."\n\nResponda só com um objeto JSON, sem texto fora dele, com as chaves \"codigo\" (o código da lista que corresponde ao mesmo objeto do item, ou null se nenhum corresponder) "
            .'e "termos" (de 2 a 4 palavras, sem marca, para procurar contratações do mesmo objeto no Portal Nacional de Contratações Públicas).';

        return CotacaoAutomatica::lerCatalogo($this->chamarSem($item->orgao_id, $item->processo_id, 'precos', 'catalogo', $pedido, $u), array_column($candidatos, 'codigo'));
    }

    /**
     * Cotação automática, passo 2: quais preços encontrados são de objeto equivalente ao item (mesma especificação
     * essencial e mesma unidade de fornecimento) e a justificativa da pesquisa para os autos (art. 23). A IA não
     * calcula nem escolhe valores: só diz o que é comparável e por quê.
     *
     * @param  list<array{valor: float, data: string, orgao: string, unidade: ?string, quantidade: ?float, descricao: ?string, origem?: string}>  $candidatos
     * @return array{compativeis: array<int, string>, justificativa: ?string}
     */
    public function cotarAvaliar(ItemProcesso $item, array $candidatos, User $u): array
    {
        $this->exigirHabilitada();
        $linhas = ["Item a cotar: {$item->descricao}. Unidade: {$item->unidade_medida}. Quantidade: ".numero_br($item->quantidade, 0).'.', '', 'Preços encontrados em contratações públicas do último ano:'];
        foreach ($candidatos as $i => $c) {
            $linhas[] = "[{$i}] ".Str::limit((string) ($c['descricao'] ?? '(sem descrição)'), 300)
                .' | unidade: '.($c['unidade'] ?? 'não informada')
                .' | R$ '.number_format((float) $c['valor'], 2, ',', '.')
                .' | '.($c['origem'] ?? 'Compras.gov.br').', '.$c['orgao'].', '.$c['data'];
        }
        $pedido = Anonimizador::limpar(implode("\n", $linhas))
            ."\n\nAvalie cada preço. É compatível só se o objeto for o mesmo do item, com especificação essencial equivalente e a mesma unidade de fornecimento "
            .'(caixa com 10 não equivale a unidade, resma não equivale a folha). Marca diferente não torna incompatível. '
            .'Responda só com um objeto JSON, sem texto fora dele, com as chaves "avaliacoes" (array com um elemento por preço: {"i": índice, "compativel": true ou false, "motivo": frase curta}) '
            .'e "justificativa" (um parágrafo para os autos explicando os critérios usados para considerar os preços comparáveis e por que os demais foram afastados, '
            .'citando o art. 23, § 1º, II, da Lei 14.133/2021 e, quando o ente a adotar, a IN SEGES/ME nº 65/2021; não cite valores nem o preço final).';

        return CotacaoAutomatica::lerAvaliacao($this->chamarSem($item->orgao_id, $item->processo_id, 'precos', 'cotar', $pedido, $u), count($candidatos));
    }

    /** O mesmo limite diário vale para todo uso da IA. */
    public static function limiteAtingido(User $u): bool
    {
        return UsoIa::where('user_id', $u->id)->where('created_at', '>=', today())->count() >= (int) Parametros::valor('ia_limite_diario', $u->orgao_id);
    }

    private function exigirHabilitada(): void
    {
        if (! self::habilitada()) {
            throw new RegraViolada(['A NexMind IA não está ativada neste servidor.']);
        }
    }

    /** Registro de uso: quem, quando, em qual etapa e quanto consumiu. O texto não é guardado; só o hash do pedido. */
    private function chamarSem(int $orgaoId, ?int $processoId, string $tipo, string $acao, string $pedido, User $u): string
    {
        $r = $this->cliente->responder(self::SISTEMA, $pedido);
        UsoIa::create([
            'orgao_id' => $orgaoId, 'user_id' => $u->id, 'processo_id' => $processoId, 'tipo_documento' => $tipo, 'acao' => $acao,
            'hash_pedido' => hash('sha256', $pedido), 'tokens_entrada' => $r['tokens_entrada'], 'tokens_saida' => $r['tokens_saida'],
        ]);

        return $r['texto'];
    }

    private function exigir(string $tipo): array
    {
        if (! self::habilitada()) {
            throw new RegraViolada(['A NexMind IA não está ativada neste servidor.']);
        }
        if (in_array($tipo, self::BLOQUEADAS, true)) {
            throw new RegraViolada(['A IA não redige parecer jurídico nem a decisão da autoridade: são atos de quem tem a competência.']);
        }

        return TiposDocumento::get($tipo) ?? throw new RegraViolada(['Peça desconhecida.']);
    }

    private function contexto(Processo $p, array $def, array $conteudo): string
    {
        $linhas = ["Processo {$p->identificacao()}. Objeto: {$p->objeto}", 'Modalidade: '.$p->modalidadeEnum()->rotulo().'. Categoria: '.$p->categoriaEnum()->rotulo().'.'];
        if ((float) $p->valor_estimado > 0) {
            $linhas[] = 'Valor estimado: R$ '.number_format((float) $p->valor_estimado, 2, ',', '.').'.';
        }
        foreach ($p->itens()->limit(30)->get() as $i) {
            $linhas[] = "Item {$i->numero}: {$i->descricao} ({$i->quantidade} {$i->unidade_medida}).";
        }
        $linhas[] = "\nConteúdo atual da peça:";
        foreach ($def['campos'] as $c => $d) {
            $v = trim((string) ($conteudo[$c] ?? ''));
            $linhas[] = "- {$d['rotulo']}: ".($v === '' ? '(vazio)' : Str::limit($v, 3000));
        }

        return Anonimizador::limpar(implode("\n", $linhas));
    }

    private function chamar(Processo $p, string $tipo, string $acao, string $pedido, User $u): string
    {
        return $this->chamarSem($p->orgao_id, $p->id, $tipo, $acao, $pedido, $u);
    }
}
