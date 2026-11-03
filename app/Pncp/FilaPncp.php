<?php

namespace App\Pncp;

use App\Jobs\EnviarAoPncp;
use App\Models\EnvioPncp;
use App\Models\ItemProcesso;
use App\Models\Processo;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Coloca um envio na fila. O corpo é montado na hora do agendamento (retrato do que foi decidido)
 * e o envio de fato acontece no job, com novas tentativas para falhas do PNCP.
 */
class FilaPncp
{
    public function __construct(private MontadorPayload $montador, private DocumentosPncp $documentos) {}

    /** Recursos cujo envio leva um documento em PDF. */
    public const COM_DOCUMENTO = ['compra', 'arquivo_contrato', 'arquivo_ata'];

    public function agendar(Model $alvo, string $recurso, string $operacao, ?User $usuario = null, ?string $justificativa = null): EnvioPncp
    {
        // Nunca reenviar uma inclusão que já tem número no PNCP: vira retificação com o objeto completo.
        if ($operacao === 'inserir' && in_array($recurso, ['compra', 'contrato', 'termo', 'ata', 'pca'], true) && $alvo->getAttribute('numero_controle_pncp')) {
            $operacao = 'retificar';
        }

        $montado = $this->montador->montar($alvo, $recurso, $operacao);
        $orgao = $alvo->getRelationValue('orgao') ?? $alvo->orgao;

        if (in_array($operacao, ['retificar', 'excluir'], true)) {
            $justificativa = trim((string) $justificativa) ?: ($operacao === 'excluir' ? null : 'Atualização dos dados da contratação pelo órgão.');
            if ($justificativa === null) {
                $montado['pendencias'][] = 'Informe a justificativa da exclusão.';
            }
            $montado['corpo'] = $operacao === 'excluir' ? ['justificativa' => $justificativa] : $montado['corpo'] + ['justificativa' => $justificativa];
        }

        // Documento: só na inclusão (a compra leva o edital ou o aviso; os endpoints de arquivo, o PDF).
        $documento = null;
        if (in_array($recurso, self::COM_DOCUMENTO, true) && ($recurso !== 'compra' || $operacao === 'inserir') && $montado['pendencias'] === []) {
            $documento = match ($recurso) {
                'compra' => $this->documentos->daCompra($alvo),
                'arquivo_contrato' => $this->documentos->doContrato($alvo),
                'arquivo_ata' => $this->documentos->daAta($alvo),
            };
            if ($documento === null) {
                $montado['pendencias'][] = match ($recurso) {
                    'compra' => 'Conclua a peça Minuta do edital: o edital vai anexado à publicação (art. 54).',
                    default => 'Anexe ao contrato o arquivo assinado em PDF.',
                };
            }
        }

        // Turma de treinamento: o envio é montado e conferido como de verdade, mas nunca sai do sistema.
        $treinamento = (bool) $orgao?->treinamento;

        $envio = EnvioPncp::create([
            'orgao_id' => $alvo->getAttribute('orgao_id'),
            'user_id' => $usuario?->id,
            'publicavel_type' => $alvo->getMorphClass(),
            'publicavel_id' => $alvo->getKey(),
            'recurso' => $recurso,
            'operacao' => $operacao,
            'estado' => $montado['pendencias'] === [] ? ($treinamento ? 'simulado' : 'pendente') : 'erro',
            'ambiente' => $treinamento ? 'treinamento' : ($orgao?->pncp_ambiente ?? config('nexplangov.pncp.ambiente')),
            'payload' => $montado['corpo'],
            'arquivo_caminho' => $documento['caminho'] ?? null,
            'documento_titulo' => $documento['titulo'] ?? null,
            'tipo_documento_id' => $documento['tipo'] ?? null,
            'justificativa' => $justificativa,
            'metodo' => $montado['metodo'],
            'rota' => $montado['rota'],
            'erro' => $montado['pendencias'] === [] ? null : 'Antes de enviar: '.implode(' ', $montado['pendencias']),
            'resposta' => $treinamento ? ['simulado' => true, 'mensagem' => 'Ambiente de treinamento: nada foi enviado ao PNCP.'] : null,
        ]);

        if ($envio->estado === 'pendente') {
            EnviarAoPncp::dispatch($envio->id)->afterCommit();
        }

        return $envio;
    }

    /**
     * Um resultado por item homologado com vencedor. Item que já tem resultado no PNCP vira retificação.
     *
     * @return list<EnvioPncp>
     */
    public function agendarResultados(Processo $processo, ?User $usuario = null): array
    {
        return $processo->itens()->with(['processo.orgao', 'fornecedorVencedor'])
            ->where('situacao', 'homologado')->whereNotNull('fornecedor_vencedor_id')->orderBy('numero')->get()
            ->map(fn (ItemProcesso $i) => $this->agendar($i, 'resultado', $i->sequencial_resultado_pncp ? 'retificar' : 'inserir', $usuario))
            ->all();
    }
}
