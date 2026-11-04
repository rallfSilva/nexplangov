<?php

namespace App\Integracoes\Devolucao;

use App\Models\Orgao;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Monta o pacote de devolução dos dados de um órgão: cada tabela com registros do órgão em CSV (para
 * planilha) e JSON Lines (para importar em outro sistema), os arquivos enviados (peças, contratos,
 * gravações), a trilha de auditoria e um manifesto com o SHA-256 de cada arquivo do pacote.
 *
 * Os registros saem pelo modelo quando há um: campos cifrados (CPF) saem legíveis, porque os dados são
 * do órgão; senha, segredo do 2FA, tokens e o HMAC de busca do CPF nunca saem.
 */
final class PacoteDadosOrgao
{
    private const LOTE = 500;

    /** Colunas que nunca saem, mesmo em tabela sem modelo. */
    private const COLUNAS_PROIBIDAS = '/(password|remember_token|two_factor|secret|recovery_codes|_token$|^(cpf|documento)_hash$)/i';

    /** @return array{arquivo: string, sha256: string, tamanho: int, resumo: array{tabelas: array<string, int>, arquivos: int, ausentes: int}} */
    public function gerar(Orgao $orgao, string $destino, ?User $solicitante = null): array
    {
        $tmp = storage_path('app/private/tmp-devolucao-'.Str::random(16));
        File::ensureDirectoryExists($tmp.'/dados');

        $zip = new ZipArchive;
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o pacote de devolução.');
        }

        try {
            $manifesto = ['orgao' => ['nome' => $orgao->nome, 'sigla' => $orgao->sigla, 'cnpj' => $orgao->cnpj], 'gerado_em' => now()->toIso8601String(),
                'solicitado_por' => $solicitante?->name, 'sistema' => 'NexPlanGov', 'tabelas' => [], 'arquivos' => []];
            $caminhosArquivos = [];

            // O próprio órgão.
            $this->tabela($zip, $tmp, 'orgaos', [$orgao->toArray()], $manifesto);

            $modelos = $this->modelosPorTabela();
            foreach ($this->tabelasDoOrgao() as $tabela) {
                $colunas = Schema::getColumnListing($tabela);
                $consulta = DB::table($tabela)->where('orgao_id', $orgao->id);
                $temId = in_array('id', $colunas, true);
                $modelo = $modelos[$tabela] ?? null;

                $linhas = (function () use ($consulta, $temId, $modelo, &$caminhosArquivos) {
                    $cursor = $temId ? $consulta->orderBy('id')->lazyById(self::LOTE) : $consulta->cursor();
                    foreach ($cursor as $registro) {
                        if ($modelo) {
                            $m = (new $modelo)->newFromBuilder($registro);
                            $linha = $m->makeVisible($m->getHidden())->toArray();
                        } else {
                            $linha = (array) $registro;
                        }
                        foreach ($linha as $campo => $valor) {
                            if (is_string($valor) && $valor !== '' && str_ends_with($campo, '_caminho')) {
                                $caminhosArquivos[$valor] = true;
                            }
                        }
                        yield $linha;
                    }
                })();
                $this->tabela($zip, $tmp, $tabela, $linhas, $manifesto);
            }

            // Perfis de cada usuário (tabela do pacote de permissões, sem orgao_id).
            $perfis = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')->join('users', 'users.id', '=', 'model_has_roles.model_id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass())->where('users.orgao_id', $orgao->id)
                ->orderBy('users.id')->get(['users.id as user_id', 'users.email', 'roles.name as perfil'])->map(fn ($r) => (array) $r)->all();
            $this->tabela($zip, $tmp, 'perfis_usuarios', $perfis, $manifesto);

            // Arquivos: tudo o que está na pasta do órgão e tudo o que os registros apontam.
            $disco = Storage::disk('local');
            foreach ($disco->allFiles("orgaos/{$orgao->id}") as $c) {
                $caminhosArquivos[$c] = true;
            }
            $ausentes = 0;
            foreach (array_keys($caminhosArquivos) as $caminho) {
                if (str_contains($caminho, '..') || ! $disco->exists($caminho)) {
                    $ausentes++;

                    continue;
                }
                $nome = 'arquivos/'.ltrim($caminho, '/');
                $zip->addFile($disco->path($caminho), $nome);
                $manifesto['arquivos'][$nome] = ['sha256' => hash_file('sha256', $disco->path($caminho)), 'bytes' => $disco->size($caminho)];
            }

            $zip->addFromString('LEIA-ME.txt', $this->leiaMe($orgao, $manifesto, $ausentes));
            $zip->addFromString('manifesto.json', json_encode($manifesto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $zip->close();
        } finally {
            File::deleteDirectory($tmp);
        }

        return [
            'arquivo' => $destino, 'sha256' => hash_file('sha256', $destino), 'tamanho' => filesize($destino),
            'resumo' => ['tabelas' => array_map(fn ($t) => $t['registros'], $manifesto['tabelas']), 'arquivos' => count($manifesto['arquivos']), 'ausentes' => $ausentes],
        ];
    }

    /** @return list<string> tabelas com a coluna orgao_id, em ordem alfabética */
    public function tabelasDoOrgao(): array
    {
        $tabelas = [];
        foreach (Schema::getTables() as $t) {
            $nome = $t['name'];
            if (! in_array($t['schema'] ?? null, [null, 'public', 'main'], true)) {
                continue;
            }
            if (in_array($nome, ['pacotes_devolucao'], true) || ! Schema::hasColumn($nome, 'orgao_id')) {
                continue;
            }
            $tabelas[] = $nome;
        }
        sort($tabelas);

        return array_values(array_unique($tabelas));
    }

    /**
     * @param  iterable<array<string, mixed>>  $linhas
     * @param  array<string, mixed>  $manifesto
     */
    private function tabela(ZipArchive $zip, string $tmp, string $tabela, iterable $linhas, array &$manifesto): void
    {
        $csv = "{$tmp}/dados/{$tabela}.csv";
        $jsonl = "{$tmp}/dados/{$tabela}.jsonl";
        $hc = fopen($csv, 'w');
        $hj = fopen($jsonl, 'w');
        fwrite($hc, "\xEF\xBB\xBF");
        $colunas = null;
        $n = 0;

        foreach ($linhas as $linha) {
            $linha = array_filter($linha, fn ($c) => ! preg_match(self::COLUNAS_PROIBIDAS, (string) $c), ARRAY_FILTER_USE_KEY);
            if ($colunas === null) {
                $colunas = array_keys($linha);
                fputcsv($hc, $colunas, ';', '"', '');
            }
            fputcsv($hc, array_map(fn ($c) => $this->celulaCsv($linha[$c] ?? null), $colunas), ';', '"', '');
            fwrite($hj, json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            $n++;
        }
        fclose($hc);
        fclose($hj);

        foreach ([$csv, $jsonl] as $arq) {
            $nome = 'dados/'.basename($arq);
            $zip->addFile($arq, $nome);
            $manifesto['arquivos'][$nome] = ['sha256' => hash_file('sha256', $arq), 'bytes' => filesize($arq)];
        }
        // ZipArchive só lê o arquivo no close(): os temporários ficam até lá.
        $manifesto['tabelas'][$tabela] = ['registros' => $n, 'csv' => "dados/{$tabela}.csv", 'json' => "dados/{$tabela}.jsonl"];
    }

    private function celulaCsv(mixed $v): mixed
    {
        if (is_array($v) || is_object($v)) {
            return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        // Proteção contra fórmula ao abrir no Excel (CSV injection).
        if (is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && ! is_numeric($v)) {
            return "'".$v;
        }

        return $v;
    }

    /** @return array<string, class-string<Model>> */
    private function modelosPorTabela(): array
    {
        $mapa = [];
        foreach (File::files(app_path('Models')) as $arquivo) {
            $classe = 'App\\Models\\'.$arquivo->getFilenameWithoutExtension();
            if (class_exists($classe) && is_subclass_of($classe, Model::class) && ! (new \ReflectionClass($classe))->isAbstract()) {
                $mapa[(new $classe)->getTable()] = $classe;
            }
        }

        return $mapa;
    }

    /** @param  array<string, mixed>  $manifesto */
    private function leiaMe(Orgao $orgao, array $manifesto, int $ausentes): string
    {
        $linhas = [
            'PACOTE DE DEVOLUÇÃO DE DADOS - NexPlanGov',
            '',
            "Órgão: {$orgao->nome} ({$orgao->sigla}) - CNPJ {$orgao->cnpj}",
            'Gerado em: '.now()->format('d/m/Y H:i').($manifesto['solicitado_por'] ? " por {$manifesto['solicitado_por']}" : ''),
            '',
            'CONTEÚDO',
            '- dados/<tabela>.csv: registros em CSV (UTF-8, separador ponto e vírgula), para abrir em planilha.',
            '- dados/<tabela>.jsonl: os mesmos registros em JSON Lines (um registro por linha), para importar em outro sistema.',
            '- dados/registros_auditoria.*: trilha de auditoria completa do órgão (quem fez o quê e quando).',
            '- dados/perfis_usuarios.*: perfil de acesso de cada usuário.',
            '- arquivos/: arquivos enviados ao sistema (peças, contratos assinados, gravações de sessão etc.), no mesmo caminho em que estavam guardados.',
            '- manifesto.json: lista de tabelas, quantidade de registros e o SHA-256 de cada arquivo do pacote.',
            '',
            'REGISTROS POR TABELA',
        ];
        foreach ($manifesto['tabelas'] as $t => $info) {
            $linhas[] = sprintf('  %-40s %d', $t, $info['registros']);
        }
        $linhas[] = '';
        $linhas[] = 'Arquivos anexados: '.(count($manifesto['arquivos']) - 2 * count($manifesto['tabelas'])).($ausentes ? " ({$ausentes} referência(s) a arquivo que não estava mais no armazenamento)" : '');
        $linhas[] = '';
        $linhas[] = 'ATENÇÃO (LGPD): o pacote contém dados pessoais legíveis (por exemplo, CPF de usuários e de fornecedores pessoa física).';
        $linhas[] = 'Guarde-o em local com acesso restrito e descarte as cópias que não forem necessárias (Lei nº 13.709/2018, arts. 6º, 46 e 47).';
        $linhas[] = 'Senhas, segredos de verificação em duas etapas e tokens de acesso nunca saem do sistema.';
        $linhas[] = '';
        $linhas[] = 'Para conferir a integridade: compare o SHA-256 de cada arquivo com o valor do manifesto.json (ex.: sha256sum dados/processos.csv).';

        return implode("\r\n", $linhas)."\r\n";
    }
}
