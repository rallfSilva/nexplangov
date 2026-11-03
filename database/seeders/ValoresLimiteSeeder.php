<?php

namespace Database\Seeders;

use App\Models\ValorLimite;
use Illuminate\Database\Seeder;

/**
 * Valores da dispensa por valor (art. 75, I e II) atualizados por decreto (art. 182).
 * Os valores marcados como não conferidos aparecem com aviso na tela até o administrador
 * confirmar o número do decreto e os valores publicados no Diário Oficial da União.
 */
class ValoresLimiteSeeder extends Seeder
{
    public const VALORES = [
        [2025, 'art75_I', 125451.15, 'Decreto nº 12.343/2024', true],
        [2025, 'art75_II', 62725.59, 'Decreto nº 12.343/2024', true],
        [2026, 'art75_I', 130984.20, 'Decreto nº 12.807/2025 (conferir valor no DOU)', false],
        [2026, 'art75_II', 65492.11, 'Decreto nº 12.807/2025 (conferir valor no DOU)', false],
    ];

    public function run(): void
    {
        foreach (self::VALORES as [$ano, $dispositivo, $valor, $ato, $conferido]) {
            ValorLimite::firstOrCreate(
                ['ano' => $ano, 'dispositivo' => $dispositivo],
                ['valor' => $valor, 'ato_normativo' => $ato, 'conferido' => $conferido],
            );
        }
    }
}
