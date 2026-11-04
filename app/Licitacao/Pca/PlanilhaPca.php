<?php

namespace App\Licitacao\Pca;

use App\Licitacao\Enums\CategoriaProcesso;
use App\Models\ItemPca;
use DateTimeInterface;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as LeitorXlsx;
use RuntimeException;

/**
 * Lê a planilha de itens do PCA (XLSX ou CSV) e gera o modelo para preenchimento.
 * Os cabeçalhos são reconhecidos sem acento e sem diferença de maiúsculas, com sinônimos comuns
 * nas planilhas dos órgãos ("qtd", "valor unitário", "secretaria"...).
 */
final class PlanilhaPca
{
    public const MAX_LINHAS = 2000;

    /** Colunas do modelo, na ordem, com o texto de ajuda da segunda linha. */
    public const COLUNAS = [
        'tipo' => 'M = material (CATMAT) ou S = serviço (CATSER)',
        'categoria' => 'Código ou nome: '.'1 Cessão, 2 Compras, 3 Informática (TIC), 4 Internacional, 5 Locação de imóveis, 6 Mão de obra, 7 Obras, 8 Serviços, 9 Serviços de engenharia, 10 Serviços de saúde',
        'codigo_catmat_catser' => 'Só números (opcional)',
        'descricao' => 'Descrição do item',
        'unidade_fornecimento' => 'UN, CX, RESMA, MÊS...',
        'quantidade' => 'Número maior que zero',
        'valor_unitario_estimado' => 'Em reais, ex.: 1.234,56',
        'unidade_requisitante' => 'Sigla da unidade cadastrada (opcional)',
        'data_desejada' => 'dd/mm/aaaa (opcional)',
        'prioridade' => 'Alta, Média ou Baixa',
        'natureza_despesa' => 'Ex.: 3.3.90.30.16 (opcional)',
    ];

    /** Sinônimos aceitos no cabeçalho → campo do item. */
    private const SINONIMOS = [
        'tipo' => 'material_servico', 'material_servico' => 'material_servico', 'material_ou_servico' => 'material_servico',
        'categoria' => 'categoria', 'categoria_do_item' => 'categoria',
        'codigo' => 'codigo_catalogo', 'codigo_catalogo' => 'codigo_catalogo', 'codigo_catmat_catser' => 'codigo_catalogo', 'catmat' => 'codigo_catalogo', 'catser' => 'codigo_catalogo', 'catmat_catser' => 'codigo_catalogo', 'codigo_do_item' => 'codigo_catalogo',
        'descricao' => 'descricao', 'descricao_do_item' => 'descricao', 'objeto' => 'descricao', 'item' => 'descricao',
        'unidade' => 'unidade_fornecimento', 'unidade_fornecimento' => 'unidade_fornecimento', 'unidade_de_fornecimento' => 'unidade_fornecimento', 'unidade_de_medida' => 'unidade_fornecimento', 'und' => 'unidade_fornecimento', 'un' => 'unidade_fornecimento',
        'quantidade' => 'quantidade', 'qtd' => 'quantidade', 'qtde' => 'quantidade', 'quant' => 'quantidade',
        'valor_unitario' => 'valor_unitario_estimado', 'valor_unitario_estimado' => 'valor_unitario_estimado', 'preco_unitario' => 'valor_unitario_estimado', 'vlr_unitario' => 'valor_unitario_estimado',
        'unidade_requisitante' => 'unidade_requisitante', 'secretaria' => 'unidade_requisitante', 'setor' => 'unidade_requisitante', 'requisitante' => 'unidade_requisitante',
        'data_desejada' => 'data_desejada', 'data' => 'data_desejada', 'previsao' => 'data_desejada', 'data_prevista' => 'data_desejada',
        'prioridade' => 'prioridade',
        'natureza_despesa' => 'natureza_despesa', 'natureza_da_despesa' => 'natureza_despesa', 'natureza' => 'natureza_despesa', 'elemento_de_despesa' => 'natureza_despesa',
    ];

    /**
     * @return list<array{linha: int, valores: array<string, mixed>}> linhas com conteúdo, já com as colunas reconhecidas
     *
     * @throws RuntimeException quando o arquivo não pode ser lido ou não tem cabeçalho reconhecível
     */
    public static function ler(string $caminho, string $extensao): array
    {
        $linhas = strtolower($extensao) === 'xlsx' ? self::linhasXlsx($caminho) : self::linhasCsv($caminho);

        $cabecalho = null;
        $saida = [];
        foreach ($linhas as $n => $celulas) {
            if (self::vazia($celulas)) {
                continue;
            }
            if ($cabecalho === null) {
                $cabecalho = array_map(fn ($c) => self::SINONIMOS[self::chave($c)] ?? null, $celulas);
                if (! in_array('descricao', $cabecalho, true) || ! in_array('quantidade', $cabecalho, true)) {
                    throw new RuntimeException('Não encontramos o cabeçalho da planilha. Use o modelo: a primeira linha precisa ter, no mínimo, as colunas "descricao" e "quantidade".');
                }

                continue;
            }

            $valores = [];
            foreach ($cabecalho as $i => $campo) {
                if ($campo !== null && ! array_key_exists($campo, $valores)) {
                    $valores[$campo] = $celulas[$i] ?? null;
                }
            }
            // A linha de ajuda do modelo é ignorada.
            if (is_string($valores['quantidade'] ?? null) && str_starts_with($valores['quantidade'], 'Número maior')) {
                continue;
            }
            $saida[] = ['linha' => $n + 1, 'valores' => $valores];

            if (count($saida) > self::MAX_LINHAS) {
                throw new RuntimeException('A planilha passa de '.self::MAX_LINHAS.' itens. Divida em arquivos menores.');
            }
        }

        if ($cabecalho === null) {
            throw new RuntimeException('A planilha está vazia.');
        }

        return $saida;
    }

    /**
     * Converte os valores da planilha para os campos do item, sem validar.
     *
     * @param  array<string, mixed>  $v
     * @param  array<string, int>  $unidadesPorSigla
     * @return array{dados: array<string, mixed>, erros: list<string>}
     */
    public static function normalizar(array $v, array $unidadesPorSigla): array
    {
        $erros = [];
        $texto = fn ($x) => $x === null ? null : (trim((string) ($x instanceof DateTimeInterface ? $x->format('d/m/Y') : $x)) ?: null);

        $tipo = Str::upper(Str::ascii((string) $texto($v['material_servico'] ?? null)));
        $tipo = match (true) {
            $tipo === 'M' || str_starts_with($tipo, 'MAT') => 'M',
            $tipo === 'S' || str_starts_with($tipo, 'SERV') => 'S',
            default => $tipo,
        };

        $categoria = self::categoria($texto($v['categoria'] ?? null));
        if (($v['categoria'] ?? null) !== null && $categoria === null) {
            $erros[] = 'Categoria "'.$texto($v['categoria']).'" não reconhecida.';
        }

        $unidadeId = null;
        $sigla = $texto($v['unidade_requisitante'] ?? null);
        if ($sigla !== null) {
            $unidadeId = $unidadesPorSigla[Str::upper($sigla)] ?? null;
            if ($unidadeId === null) {
                $erros[] = "Unidade requisitante \"{$sigla}\" não está cadastrada no órgão.";
            }
        }

        $data = self::data($v['data_desejada'] ?? null);
        if (($v['data_desejada'] ?? null) !== null && $texto($v['data_desejada']) !== null && $data === null) {
            $erros[] = 'Data desejada "'.$texto($v['data_desejada']).'" inválida; use dd/mm/aaaa.';
        }

        $codigo = $texto($v['codigo_catalogo'] ?? null);
        if ($codigo !== null && preg_match('/^\d+\.0+$/', $codigo)) {
            $codigo = strstr($codigo, '.', true); // número do Excel: 12345.0
        }

        return ['dados' => [
            'material_servico' => $tipo ?: 'M',
            'categoria' => $categoria ?? 2,
            'codigo_catalogo' => $codigo,
            'descricao' => $texto($v['descricao'] ?? null),
            'unidade_fornecimento' => $texto($v['unidade_fornecimento'] ?? null),
            'quantidade' => self::numero($v['quantidade'] ?? null),
            'valor_unitario_estimado' => self::numero($v['valor_unitario_estimado'] ?? null),
            'unidade_id' => $unidadeId,
            'data_desejada' => $data,
            'prioridade' => self::prioridade($texto($v['prioridade'] ?? null)),
            'natureza_despesa' => $texto($v['natureza_despesa'] ?? null),
        ], 'erros' => $erros];
    }

    /** Chave de comparação para achar o mesmo item duas vezes (código, descrição e unidade). */
    public static function chaveDuplicidade(?string $codigo, ?string $descricao, ?string $unidade): string
    {
        $n = fn ($s) => preg_replace('/\s+/', ' ', Str::lower(Str::ascii(trim((string) $s))));

        return $n($codigo).'|'.$n($descricao).'|'.$n($unidade);
    }

    public static function chaveDoItem(ItemPca $i): string
    {
        return self::chaveDuplicidade($i->codigo_catalogo, $i->descricao, $i->unidade_fornecimento);
    }

    private static function categoria(?string $valor): ?int
    {
        if ($valor === null) {
            return null;
        }
        if (ctype_digit($valor)) {
            return CategoriaProcesso::tryFrom((int) $valor)?->value;
        }
        $alvo = self::chave($valor);
        foreach (CategoriaProcesso::cases() as $c) {
            $rotulo = self::chave($c->rotulo());
            if ($rotulo === $alvo || str_starts_with($rotulo, $alvo.'_') || ($alvo === 'informatica' && $c === CategoriaProcesso::Informatica)) {
                return $c->value;
            }
        }

        return null;
    }

    private static function prioridade(?string $valor): int|string|null
    {
        if ($valor === null) {
            return 2;
        }

        return match (self::chave($valor)) {
            '1', 'alta' => 1,
            '2', 'media' => 2,
            '3', 'baixa' => 3,
            default => $valor,
        };
    }

    private static function numero(mixed $valor): float|string|null
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }
        $limpo = trim(str_replace(['R$', ' '], '', (string) $valor));

        return decimal_br($limpo) ?? (string) $valor;
    }

    private static function data(mixed $valor): ?string
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d');
        }
        $t = trim((string) $valor);
        if ($t === '') {
            return null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $t, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $t, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }
        if (ctype_digit($t) && (int) $t > 30000 && (int) $t < 80000) { // data serial do Excel
            return gmdate('Y-m-d', ((int) $t - 25569) * 86400);
        }

        return null;
    }

    private static function chave(mixed $texto): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(Str::ascii(trim((string) $texto)))), '_');
    }

    /** @param  list<mixed>  $celulas */
    private static function vazia(array $celulas): bool
    {
        foreach ($celulas as $c) {
            if ($c !== null && trim((string) ($c instanceof DateTimeInterface ? 'x' : $c)) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return iterable<int, list<mixed>> */
    private static function linhasXlsx(string $caminho): iterable
    {
        $leitor = new LeitorXlsx;
        try {
            $leitor->open($caminho);
        } catch (\Throwable) {
            throw new RuntimeException('Não foi possível abrir a planilha. Confira se é um arquivo .xlsx válido.');
        }
        $linhas = [];
        foreach ($leitor->getSheetIterator() as $aba) {
            foreach ($aba->getRowIterator() as $linha) {
                $linhas[] = $linha->toArray();
                if (count($linhas) > self::MAX_LINHAS + 50) {
                    break;
                }
            }
            break; // só a primeira aba
        }
        $leitor->close();

        return $linhas;
    }

    /** @return list<list<string|null>> */
    private static function linhasCsv(string $caminho): array
    {
        $conteudo = (string) file_get_contents($caminho);
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
        $primeira = strtok($conteudo, "\n") ?: '';
        $separador = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';

        $h = fopen('php://temp', 'r+');
        fwrite($h, $conteudo);
        rewind($h);
        $linhas = [];
        while (($c = fgetcsv($h, null, $separador, '"', '')) !== false) {
            $linhas[] = $c;
            if (count($linhas) > self::MAX_LINHAS + 50) {
                break;
            }
        }
        fclose($h);

        return $linhas;
    }
}
