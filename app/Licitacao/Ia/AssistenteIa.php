<?php

namespace App\Licitacao\Ia;

use App\Licitacao\RegraViolada;
use App\Licitacao\TiposDocumento;
use App\Models\Processo;
use App\Models\User;
use App\Models\UsoIa;
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
        $r = $this->cliente->responder(self::SISTEMA, $pedido);

        // Registro de uso: quem, quando, em qual peça e quanto consumiu. O texto não é guardado; só o hash do pedido.
        UsoIa::create([
            'orgao_id' => $p->orgao_id, 'user_id' => $u->id, 'processo_id' => $p->id, 'tipo_documento' => $tipo, 'acao' => $acao,
            'hash_pedido' => hash('sha256', $pedido), 'tokens_entrada' => $r['tokens_entrada'], 'tokens_saida' => $r['tokens_saida'],
        ]);

        return $r['texto'];
    }
}
