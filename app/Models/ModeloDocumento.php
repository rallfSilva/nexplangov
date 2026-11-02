<?php

namespace App\Models;

use App\Licitacao\Enums\CriterioJulgamento;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrgao;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Minuta editável por órgão. O texto usa campos dinâmicos entre chaves duplas, como {{objeto}},
 * trocados pelos dados do processo na hora de aplicar. Cada edição guarda a versão anterior.
 */
#[Fillable(['orgao_id', 'codigo', 'tipo', 'titulo', 'descricao', 'aplicacao', 'categoria', 'conteudo', 'versao', 'padrao_nexmind', 'ativo'])]
class ModeloDocumento extends Model
{
    use Auditable, BelongsToOrgao;

    protected $table = 'modelos_documento';

    /** @var array<string, string> campo dinâmico => descrição */
    public const CAMPOS_DINAMICOS = [
        'orgao' => 'Nome do órgão',
        'cnpj_orgao' => 'CNPJ do órgão',
        'numero_processo' => 'Número do processo, como 012/2026',
        'objeto' => 'Objeto da contratação',
        'modalidade' => 'Modalidade (pregão eletrônico, dispensa...)',
        'amparo_legal' => 'Amparo legal',
        'categoria' => 'Categoria (compra, serviço, obra...)',
        'criterio_julgamento' => 'Critério de julgamento',
        'natureza_despesa' => 'Natureza da despesa',
        'valor_estimado' => 'Valor estimado total',
        'unidade' => 'Unidade requisitante',
        'responsavel' => 'Responsável pelo processo',
        'data' => 'Data de hoje',
        'ano' => 'Ano do processo',
    ];

    protected function casts(): array
    {
        return ['conteudo' => 'array', 'padrao_nexmind' => 'boolean', 'ativo' => 'boolean', 'categoria' => 'integer'];
    }

    public function versoes(): HasMany
    {
        return $this->hasMany(ModeloDocumentoVersao::class, 'modelo_id')->orderByDesc('versao');
    }

    /** Preenche os campos dinâmicos com os dados do processo. O que não existir fica marcado para preencher. */
    public function renderizar(Processo $processo): array
    {
        $valores = self::valoresDoProcesso($processo);
        $saida = [];

        foreach ($this->conteudo as $campo => $texto) {
            $saida[$campo] = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => $valores[$m[1]] ?? '[preencher: '.$m[1].']', (string) $texto);
        }

        return $saida;
    }

    /** @return array<string, string> */
    public static function valoresDoProcesso(Processo $p): array
    {
        $p->loadMissing(['orgao', 'unidade', 'responsavel']);

        return [
            'orgao' => $p->orgao?->nome ?? '',
            'cnpj_orgao' => $p->orgao?->cnpj ?? '',
            'numero_processo' => $p->identificacao(),
            'objeto' => $p->objeto,
            'modalidade' => $p->modalidadeEnum()->rotulo(),
            'amparo_legal' => $p->amparoEnum()->rotulo(),
            'categoria' => $p->categoriaEnum()->rotulo(),
            'criterio_julgamento' => CriterioJulgamento::from($p->criterio_julgamento)->rotulo(),
            'natureza_despesa' => (string) $p->natureza_despesa,
            'valor_estimado' => reais($p->valor_estimado),
            'unidade' => $p->unidade ? "{$p->unidade->sigla} - {$p->unidade->nome}" : '',
            'responsavel' => $p->responsavel?->name ?? '',
            'data' => now()->format('d/m/Y'),
            'ano' => (string) $p->ano,
        ];
    }

    protected function rotuloAuditoria(): string
    {
        return "Modelo {$this->titulo}";
    }
}
