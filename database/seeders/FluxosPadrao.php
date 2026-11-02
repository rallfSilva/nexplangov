<?php

namespace Database\Seeders;

use App\Models\Fluxo;
use App\Models\FluxoEtapa;
use App\Models\Orgao;

/**
 * Fluxo inicial de cada órgão: só dependências, prazos e assinatura do parecer e da autorização.
 * Revisão e aprovação ficam desligadas até o órgão decidir quem faz o quê (tela Fluxos de documentos).
 */
class FluxosPadrao
{
    /** tipo => [depende de, prazo em dias, assina] */
    private const ETAPAS = [
        'dfd' => [[], 3, false],
        'etp' => [['dfd'], 10, false],
        'mapa_riscos' => [['etp'], 5, false],
        'termo_referencia' => [['dfd', 'etp'], 10, false],
        'dotacao' => [['termo_referencia'], 3, false],
        'minuta_edital' => [['termo_referencia'], 7, false],
        'minuta_contrato' => [['termo_referencia'], 7, false],
        'habilitacao' => [['termo_referencia'], 3, false],
        'razao_escolha' => [['termo_referencia'], 3, false],
        'justificativa_preco' => [['termo_referencia'], 3, false],
        'justificativa_presencial' => [['termo_referencia'], 3, false],
        'parecer_juridico' => [['termo_referencia', 'dotacao'], 5, true], // art. 53
        'autorizacao' => [['parecer_juridico'], 3, true],
    ];

    public static function instalarPara(Orgao $orgao): bool
    {
        if (Fluxo::where('orgao_id', $orgao->id)->exists()) {
            return false;
        }

        $fluxo = Fluxo::create([
            'orgao_id' => $orgao->id, 'nome' => 'Fluxo padrão NexMind', 'aplicacao' => 'todos', 'padrao' => true, 'ativo' => true,
            'descricao' => 'Ordem das peças da fase preparatória (art. 18) com prazos sugeridos. Ajuste ao ritmo do órgão.',
        ]);

        $ordem = 0;
        foreach (self::ETAPAS as $tipo => [$dep, $prazo, $assina]) {
            FluxoEtapa::create([
                'orgao_id' => $orgao->id, 'fluxo_id' => $fluxo->id, 'tipo' => $tipo, 'ordem' => ++$ordem, 'depende_de' => $dep,
                'prazo_dias' => $prazo, 'exige_revisao' => false, 'exige_aprovacao' => false, 'exige_assinatura' => $assina,
                'origem' => 'formulario', 'assinantes' => [],
            ]);
        }

        return true;
    }
}
