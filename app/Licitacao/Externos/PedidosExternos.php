<?php

namespace App\Licitacao\Externos;

use App\Licitacao\AutosDoProcesso;
use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\Regras\PedidoExterno;
use App\Licitacao\RegraViolada;
use App\Models\DocumentoProcesso;
use App\Models\Processo;
use App\Models\SolicitacaoExterna;
use App\Models\User;
use App\Notifications\PedidoExternoEnviado;
use App\Notifications\PedidoExternoRespondido;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Cria e envia por e-mail os pedidos a externos e registra a resposta que vem pelo link. */
final class PedidosExternos
{
    public function __construct(private Assinaturas $assinaturas, private AutosDoProcesso $autos) {}

    /** @return array{0: SolicitacaoExterna, 1: string} o pedido e o código do link (só existe aqui) */
    public function criar(Processo $p, User $u, string $tipo, string $nome, string $email, string $mensagem, CarbonImmutable $prazo, ?DocumentoProcesso $doc = null): array
    {
        $doc = $doc && $doc->processo_id === $p->id ? $doc : null;
        $motivos = PedidoExterno::motivosCriacao($tipo, $doc?->situacao === 'aguardando_assinatura', $prazo, today(), $mensagem);
        if ($motivos !== []) {
            throw new RegraViolada($motivos);
        }

        $token = Str::random(48);
        $s = SolicitacaoExterna::create([
            'orgao_id' => $p->orgao_id, 'processo_id' => $p->id, 'documento_id' => $tipo === 'assinatura' ? $doc?->id : null, 'tipo' => $tipo,
            'nome' => trim($nome), 'email' => mb_strtolower(trim($email)), 'mensagem' => trim($mensagem), 'token_hash' => hash('sha256', $token),
            'prazo_em' => $prazo->toDateString(), 'criado_por' => $u->id, 'enviado_em' => now(),
        ]);

        Notification::route('mail', [$s->email => $s->nome])->notify(new PedidoExternoEnviado($s, $token));

        return [$s, $token];
    }

    public function cancelar(SolicitacaoExterna $s, User $u): void
    {
        if (! $s->pendente()) {
            throw new RegraViolada(['O pedido já foi respondido ou cancelado.']);
        }

        $s->forceFill(['cancelada_em' => now(), 'cancelada_por' => $u->id])->save();
    }

    public function darCiencia(SolicitacaoExterna $s, ?string $ip): void
    {
        $this->exigirResposta($s, 'notificacao');
        $s->forceFill(['respondida_em' => now(), 'ip_resposta' => $ip, 'resposta' => 'Ciência registrada pelo link do e-mail.'])->save();
        $this->avisarCriador($s);
    }

    public function assinar(SolicitacaoExterna $s, ?string $nome, ?string $ip): void
    {
        $this->exigirResposta($s, 'assinatura');
        $this->assinaturas->assinarExterno($s->documento, $s, (string) $nome, $ip);
        $this->avisarCriador($s->refresh());
    }

    /** @param list<UploadedFile> $arquivos */
    public function receberDocumentos(SolicitacaoExterna $s, array $arquivos, ?string $observacao, ?string $ip): int
    {
        $this->exigirResposta($s, 'documentos');

        DB::transaction(function () use ($s, $arquivos, $observacao, $ip) {
            foreach ($arquivos as $i => $a) {
                $titulo = 'Documento enviado por '.$s->nome.(count($arquivos) > 1 ? ' ('.($i + 1).')' : '');
                $this->autos->juntar($s->processo, $s->nome, $a, 'documento_externo', $titulo, $observacao, $s->id);
            }
            $s->forceFill(['respondida_em' => now(), 'ip_resposta' => $ip, 'resposta' => count($arquivos).' arquivo(s) recebido(s).'.($observacao ? ' Observação: '.trim($observacao) : '')])->save();
        });
        $this->avisarCriador($s->refresh());

        return count($arquivos);
    }

    private function exigirResposta(SolicitacaoExterna $s, string $tipo): void
    {
        $motivos = PedidoExterno::motivosResposta($s->cancelada_em !== null, $s->respondida_em !== null, $s->prazo_em, now());
        if ($s->tipo !== $tipo) {
            $motivos[] = 'Este link é de outro tipo de pedido.';
        }
        if ($motivos !== []) {
            throw new RegraViolada($motivos);
        }
    }

    private function avisarCriador(SolicitacaoExterna $s): void
    {
        if ($s->criador?->ativo) {
            $s->criador->notify(new PedidoExternoRespondido($s));
        }
    }
}
