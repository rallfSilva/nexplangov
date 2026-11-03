<?php

namespace App\Licitacao\Contratos;

use App\Licitacao\Fluxo\Assinaturas;
use App\Licitacao\Regras\RegistroDePrecos;
use App\Licitacao\RegraViolada;
use App\Models\AtaAdesao;
use App\Models\AtaItem;
use App\Models\User;
use App\Support\Documento;
use App\Support\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Adesão de órgão não participante à ata de registro de preços, a "carona" (art. 86, §§ 2º a 8º).
 * Fluxo: pedido (pelo gerenciador ou pela página pública, com ofício) → aceite do fornecedor (§ 2º, III)
 * → autorização do gerenciador, que confere de novo os limites (§§ 4º e 5º), fixa o prazo para contratar
 * e emite a autorização em PDF com QR Code de verificação.
 */
final class Carona
{
    public function __construct(private RegistroDePrecos $regras = new RegistroDePrecos) {}

    /**
     * @param  array{aderente_nome: string, aderente_cnpj: string, aderente_esfera: string, quantidade: float|string, justificativa: string, vantagem: string, aderente_uf?: ?string, aderente_municipio?: ?string, solicitante_nome?: ?string, solicitante_cargo?: ?string, solicitante_email?: ?string}  $d
     */
    public function solicitar(AtaItem $ai, array $d, ?User $u, ?UploadedFile $oficio = null, string $origem = 'interno'): AtaAdesao
    {
        $ata = $ai->ata;
        $gerenciador = $ata->orgao;
        $cnpj = Documento::digitos($d['aderente_cnpj']);
        $quantidade = (float) $d['quantidade'];
        $motivos = [];

        if (! $ata->vigente()) {
            $motivos[] = 'A ata não está vigente.';
        }
        if (! Documento::cnpjValido($cnpj)) {
            $motivos[] = 'CNPJ do órgão aderente inválido.';
        } elseif ($cnpj === Documento::digitos((string) $gerenciador->cnpj)) {
            $motivos[] = 'O órgão gerenciador usa a própria ata: registre o consumo, não uma adesão.';
        }
        if (! array_key_exists($d['aderente_esfera'], AtaAdesao::ESFERAS)) {
            $motivos[] = 'Informe a esfera do órgão aderente.';
        }
        $motivos = array_merge($motivos, $this->regras->vedacaoPorEsfera((string) $gerenciador->esfera, $d['aderente_esfera']));
        if (mb_strlen(trim($d['justificativa'])) < 10 || mb_strlen(trim($d['vantagem'])) < 10) {
            $motivos[] = 'Justifique a vantagem da adesão e demonstre que os preços registrados são compatíveis com os de mercado (art. 86, § 2º, I e II).';
        }
        if ($origem === 'publico' && ! $oficio) {
            $motivos[] = 'Anexe o ofício do órgão aderente em PDF, assinado pela autoridade competente.';
        }
        $r = $this->regras->avaliarAdesao((float) $ai->quantidade_registrada, $ai->autorizadoEmAdesoes(), $this->autorizadoDoAderente($ai, $cnpj), $quantidade);
        $motivos = array_merge($motivos, $r['mensagens']);

        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        return DB::transaction(function () use ($ai, $d, $u, $oficio, $origem, $cnpj, $quantidade) {
            $a = AtaAdesao::create([
                'orgao_id' => $ai->orgao_id, 'ata_item_id' => $ai->id, 'origem' => $origem,
                'aderente_nome' => trim($d['aderente_nome']), 'aderente_cnpj' => Documento::formatarCnpj($cnpj), 'aderente_esfera' => $d['aderente_esfera'],
                'aderente_uf' => $d['aderente_uf'] ?? null, 'aderente_municipio' => isset($d['aderente_municipio']) ? trim($d['aderente_municipio']) : null,
                'solicitante_nome' => $d['solicitante_nome'] ?? null, 'solicitante_cargo' => $d['solicitante_cargo'] ?? null, 'solicitante_email' => $d['solicitante_email'] ?? null,
                'quantidade' => $quantidade, 'justificativa' => trim($d['justificativa']), 'vantagem' => trim($d['vantagem']), 'solicitada_por' => $u?->id,
            ]);
            $dados = ['protocolo' => sprintf('ADS-%d-%06d', now()->year, $a->id)];
            if ($oficio) {
                $caminho = $oficio->storeAs($this->pasta($a), 'oficio-'.Str::uuid().'.pdf', 'local');
                $dados += ['oficio_caminho' => $caminho, 'oficio_hash' => hash_file('sha256', $oficio->getRealPath())];
            }
            $a->forceFill($dados)->save();

            return $a;
        });
    }

    /**
     * Aceite ou recusa do fornecedor beneficiário (art. 86, § 2º, III). Pelo link enviado a ele (via "link")
     * ou registrado por servidor do gerenciador com o documento do fornecedor (via "registro").
     */
    public function registrarAceite(AtaAdesao $a, bool $aceita, string $representante, ?string $obs, string $via, ?UploadedFile $documento = null, ?User $u = null): void
    {
        $motivos = [];
        if ($a->situacao !== 'solicitada') {
            $motivos[] = 'Esta adesão já foi decidida.';
        }
        if ($a->fornecedor_aceite !== null) {
            $motivos[] = 'O fornecedor já respondeu a este pedido.';
        }
        if (mb_strlen(trim($representante)) < 5) {
            $motivos[] = 'Informe o nome e o cargo de quem responde pelo fornecedor.';
        }
        if ($via === 'registro' && ! $documento) {
            $motivos[] = 'Anexe o documento em que o fornecedor aceitou ou recusou (art. 86, § 2º, III).';
        }
        if (! $aceita && mb_strlen(trim((string) $obs)) < 5) {
            $motivos[] = 'Informe o motivo da recusa.';
        }
        if ($motivos) {
            throw new RegraViolada($motivos);
        }

        $dados = [
            'fornecedor_aceite' => $aceita ? 'aceito' : 'recusado', 'fornecedor_aceite_em' => now(), 'fornecedor_aceite_representante' => trim($representante),
            'fornecedor_aceite_obs' => $obs ? mb_substr(trim($obs), 0, 500) : null, 'fornecedor_aceite_via' => $via, 'fornecedor_aceite_registrado_por' => $u?->id,
        ];
        if ($documento) {
            $dados['fornecedor_aceite_caminho'] = $documento->storeAs($this->pasta($a), 'aceite-'.Str::uuid().'.'.($documento->guessExtension() ?: 'pdf'), 'local');
        }
        $a->forceFill($dados)->save();
    }

    /** Link assinado, com validade, para o fornecedor responder sem conta no sistema. */
    public function linkAceite(AtaAdesao $a): string
    {
        return URL::temporarySignedRoute('carona.aceite', now()->addDays((int) config('nexplangov.carona_link_aceite_dias', 15)), ['adesao' => $a->id]);
    }

    /** Autorização ou negativa do gerenciador; os limites e o aceite são conferidos de novo na autorização. */
    public function decidir(AtaAdesao $a, bool $autorizar, ?string $motivo, User $u): void
    {
        if ($a->situacao !== 'solicitada') {
            throw new RegraViolada(['Esta adesão já foi decidida.']);
        }
        if (! $autorizar && mb_strlen(trim((string) $motivo)) < 10) {
            throw new RegraViolada(['Informe o motivo da negativa (mínimo 10 caracteres).']);
        }

        $ai = $a->ataItem;
        $ata = $ai->ata;
        if ($autorizar) {
            $motivos = [];
            if ($a->fornecedor_aceite !== 'aceito') {
                $motivos[] = $a->fornecedor_aceite === 'recusado'
                    ? 'O fornecedor recusou o fornecimento ao aderente: a adesão só pode ser negada.'
                    : 'Falta o aceite do fornecedor beneficiário da ata (art. 86, § 2º, III).';
            }
            if (! $ata->vigente()) {
                $motivos[] = 'A ata não está vigente.';
            }
            $motivos = array_merge($motivos, $this->regras->vedacaoPorEsfera((string) $ata->orgao->esfera, $a->aderente_esfera));
            $r = $this->regras->avaliarAdesao((float) $ai->quantidade_registrada, $ai->autorizadoEmAdesoes(), $this->autorizadoDoAderente($ai, $a->aderente_cnpj), (float) $a->quantidade);
            $motivos = array_merge($motivos, $r['mensagens']);
            if ($motivos) {
                throw new RegraViolada($motivos);
            }
        }

        $dados = ['situacao' => $autorizar ? 'autorizada' : 'negada', 'motivo_decisao' => $motivo ? trim($motivo) : null, 'decidida_por' => $u->id, 'decidida_em' => now()];
        if ($autorizar) {
            $dados['prazo_contratacao'] = $this->regras->prazoContratacao(today(), $ata->vigencia_fim, (int) config('nexplangov.carona_prazo_contratacao_dias', 90))->toDateString();
            $dados['codigo_verificacao'] = strtoupper(Str::random(4).'-'.Str::random(4).'-'.Str::random(4));
        }
        $a->forceFill($dados)->save();

        if ($autorizar) {
            $this->emitirAutorizacao($a->refresh());
        }
    }

    /** Ofício de autorização em PDF, com QR Code que abre a página pública de verificação. */
    private function emitirAutorizacao(AtaAdesao $a): void
    {
        $a->load(['ataItem.item', 'ataItem.ata.orgao', 'ataItem.ata.fornecedor', 'ataItem.ata.processo', 'decisor']);
        $url = route('carona.verificar', $a->codigo_verificacao);
        $qr = 'data:image/svg+xml;base64,'.base64_encode(app(Assinaturas::class)->qrCodeSvg($url, 130));

        $conteudo = Pdf::daView('atas.autorizacao-adesao-pdf', ['a' => $a, 'url' => $url, 'qr' => $qr]);
        $caminho = $this->pasta($a).'/autorizacao-'.$a->protocolo.'.pdf';
        Storage::disk('local')->put($caminho, $conteudo);
        $a->forceFill(['autorizacao_caminho' => $caminho, 'autorizacao_hash' => hash('sha256', $conteudo)])->save();
    }

    private function pasta(AtaAdesao $a): string
    {
        return "orgaos/{$a->orgao_id}/atas/adesoes/{$a->id}";
    }

    private function autorizadoDoAderente(AtaItem $ai, string $cnpj): float
    {
        $digitos = Documento::digitos($cnpj);

        return (float) $ai->adesoes()->where('situacao', 'autorizada')->get()->filter(fn (AtaAdesao $x) => Documento::digitos($x->aderente_cnpj) === $digitos)->sum('quantidade');
    }
}
