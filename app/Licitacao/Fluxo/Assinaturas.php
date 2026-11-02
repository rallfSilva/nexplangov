<?php

namespace App\Licitacao\Fluxo;

use App\Licitacao\RegraViolada;
use App\Models\Assinatura;
use App\Models\DocumentoProcesso;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Assinatura eletrônica das peças, individual ou coletiva.
 *
 * Quem assina confirma a própria senha. O sistema grava o hash do conteúdo assinado, o instante e
 * um selo (HMAC com a chave do sistema) que impede forjar ou adulterar a assinatura. Cada assinatura
 * tem um código que vai no QR Code da folha de assinaturas e abre a página pública de validação.
 */
final class Assinaturas
{
    public function __construct(private FluxoDocumento $fluxo) {}

    public function assinar(DocumentoProcesso $d, User $u, string $senha, ?string $ip = null): Assinatura
    {
        if (! $this->fluxo->podeAssinar($u, $d)) {
            throw new RegraViolada(['Você não é signatário desta peça ou ela não está aguardando assinatura.']);
        }

        if ($d->assinaturas()->where('user_id', $u->id)->exists()) {
            throw new RegraViolada(['Você já assinou esta peça.']);
        }

        if (! Hash::check($senha, $u->password)) {
            throw new RegraViolada(['Senha incorreta. A assinatura exige a confirmação da sua senha.']);
        }

        return DB::transaction(function () use ($d, $u, $ip) {
            $agora = now();
            $hash = $d->hashAtual();
            $codigo = strtoupper(Str::random(12));

            $assinatura = Assinatura::create([
                'orgao_id' => $d->orgao_id, 'documento_id' => $d->id, 'user_id' => $u->id, 'nome' => $u->name,
                'perfil' => User::PERFIS[$u->getRoleNames()->first()] ?? 'Usuário', 'assinado_em' => $agora,
                'hash_documento' => $hash, 'codigo' => $codigo, 'selo' => $this->selo($hash, $u->id, $agora->toIso8601String(), $codigo), 'ip' => $ip,
            ]);

            $this->fluxo->concluirSeAssinada($d->refresh());

            return $assinatura;
        });
    }

    public function selo(string $hashDocumento, int $userId, string $instante, string $codigo): string
    {
        return hash_hmac('sha256', "{$hashDocumento}|{$userId}|{$instante}|{$codigo}", (string) config('app.key'));
    }

    /** @return array{valida: bool, motivo: ?string} */
    public function verificar(Assinatura $a): array
    {
        $esperado = $this->selo($a->hash_documento, $a->user_id, $a->assinado_em->toIso8601String(), $a->codigo);

        if (! hash_equals($esperado, $a->selo)) {
            return ['valida' => false, 'motivo' => 'O selo da assinatura não confere.'];
        }

        $doc = DocumentoProcesso::withoutGlobalScopes()->find($a->documento_id);

        if (! $doc || ! hash_equals($doc->hashAtual(), $a->hash_documento)) {
            return ['valida' => false, 'motivo' => 'O conteúdo da peça mudou depois da assinatura.'];
        }

        return ['valida' => true, 'motivo' => null];
    }

    public function qrCodeSvg(string $url, int $tamanho = 150): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle($tamanho, 1), new SvgImageBackEnd)))->writeString($url);
    }

    /** Código em grupos de quatro, como XXXX-XXXX-XXXX. */
    public static function formatarCodigo(string $codigo): string
    {
        return implode('-', str_split($codigo, 4));
    }
}
