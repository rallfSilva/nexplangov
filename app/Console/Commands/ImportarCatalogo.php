<?php

namespace App\Console\Commands;

use App\Models\CatalogoItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexplangov:catalogo-importar {arquivo : CSV com separador ; e colunas codigo;descricao;classe_codigo;classe_nome;unidade;situacao} {--tipo=M : M (CATMAT) ou S (CATSER)}')]
#[Description('Importa ou atualiza itens do catálogo CATMAT/CATSER a partir de um CSV (idempotente)')]
class ImportarCatalogo extends Command
{
    public function handle(): int
    {
        $tipo = strtoupper((string) $this->option('tipo'));
        $arquivo = (string) $this->argument('arquivo');

        if (! in_array($tipo, ['M', 'S'], true)) {
            $this->error('Use --tipo=M (material) ou --tipo=S (serviço).');

            return self::FAILURE;
        }
        if (! is_file($arquivo) || ($fh = fopen($arquivo, 'r')) === false) {
            $this->error('Arquivo não encontrado.');

            return self::FAILURE;
        }

        $n = 0;
        $ignoradas = 0;
        $primeira = true;
        while (($l = fgetcsv($fh, 0, ';')) !== false) {
            if ($primeira) {
                $primeira = false;
                if (! ctype_digit(trim((string) ($l[0] ?? '')))) {
                    continue; // cabeçalho
                }
            }
            $codigo = trim((string) ($l[0] ?? ''));
            $descricao = trim((string) ($l[1] ?? ''));
            if (! ctype_digit($codigo) || $descricao === '') {
                $ignoradas++;

                continue;
            }
            CatalogoItem::updateOrCreate(['tipo' => $tipo, 'codigo' => $codigo], [
                'descricao' => mb_substr(mb_convert_encoding($descricao, 'UTF-8', 'UTF-8, ISO-8859-1'), 0, 1000), 'classe_codigo' => ($l[2] ?? '') ?: null, 'classe_nome' => ($l[3] ?? '') ?: null,
                'unidade_padrao' => ($l[4] ?? '') ?: null, 'ativo' => ! in_array(mb_strtolower(trim((string) ($l[5] ?? 'ativo'))), ['inativo', 'inativa', '0', 'suspenso'], true), 'fonte' => 'importado',
            ]);
            $n++;
        }
        fclose($fh);

        $this->info("{$n} item(ns) importado(s)".($ignoradas ? ", {$ignoradas} linha(s) ignorada(s)." : '.'));

        return self::SUCCESS;
    }
}
