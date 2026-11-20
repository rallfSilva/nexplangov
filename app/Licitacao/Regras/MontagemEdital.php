<?php

namespace App\Licitacao\Regras;

use Carbon\CarbonImmutable;

/**
 * Monta o edital completo a partir do modelo já preenchido com os dados do processo: troca os trechos que os
 * modelos deixam como [informe] pelos dados do processo, do ETP, do TR e do que o servidor confirmou na tela.
 * O resultado continua sendo minuta: o que não houver dado fica entre colchetes e volta na lista de pendências,
 * para o servidor completar e a assessoria jurídica revisar (art. 53).
 */
final class MontagemEdital
{
    /** Regimes de execução de obras e serviços de engenharia (art. 46). */
    public const REGIMES = [
        'preco_unitario' => 'empreitada por preço unitário',
        'preco_global' => 'empreitada por preço global',
        'integral' => 'empreitada integral',
        'tarefa' => 'contratação por tarefa',
        'integrada' => 'contratação integrada',
        'semi_integrada' => 'contratação semi-integrada',
        'fornecimento_servico' => 'fornecimento e prestação de serviço associado',
    ];

    /** Tratamento diferenciado de ME/EPP por item (tabela 5.7 do PNCP; art. 4º da Lei 14.133 e arts. 47 a 49 da LC 123/2006). */
    public const BENEFICIOS = [
        1 => 'participação exclusiva de microempresas e empresas de pequeno porte',
        2 => 'ampla participação, com exigência de subcontratação de microempresas e empresas de pequeno porte',
        3 => 'cota reservada de até 25% para microempresas e empresas de pequeno porte',
        4 => 'ampla participação, sem tratamento diferenciado',
        5 => 'não se aplica o tratamento diferenciado',
    ];

    public const ANEXOS = ['I' => 'Termo de Referência', 'II' => 'Minuta do Contrato', 'III' => 'Mapa de Riscos', 'IV' => 'Modelos de declarações'];

    public function __construct(private DiasUteis $dias, private Garantia $garantia) {}

    /**
     * @param  array{
     *     sessao_em?: ?string, local?: ?string, modo_disputa?: ?string, prazo_assinatura?: ?int,
     *     garantia_pct?: ?float, garantia_justificativa?: ?string, habilitacao?: ?string,
     *     regime?: ?string, visita?: ?string, visita_justificativa?: ?string, cidade?: ?string,
     *     orcamento_sigiloso?: bool, grande_vulto_engenharia?: bool, bens?: bool, criterio?: int,
     *     itens?: list<array{numero: int, descricao: string, beneficio: int}>
     * }  $d
     * @return array{texto: string, pendentes: list<string>, alertas: list<string>}
     */
    public function montar(string $texto, array $d): array
    {
        $alertas = [];
        $t = str_replace("\r\n", "\n", $texto);

        // 1.2: orçamento sigiloso (art. 24) não divulga o valor no edital.
        $t = preg_replace('/ ?\[Se orçamento sigiloso, informar o fundamento do art\. 24\.\]/u', '', $t);
        if (! empty($d['orcamento_sigiloso'])) {
            $t = preg_replace('/^1\.2\. O valor estimado da contratação é[^\n]*/mu', '1.2. O orçamento estimado da contratação tem caráter sigiloso e será tornado público apenas e imediatamente após o julgamento das propostas, sem prejuízo da divulgação dos quantitativos e das demais informações necessárias à elaboração das propostas (art. 24).', $t);
        }

        // 1.3: item único ou por itens, pelo que está cadastrado no processo.
        $itens = $d['itens'] ?? [];
        if ($itens !== []) {
            $t = preg_replace('/^1\.3\. A licitação será em item único ou por itens\/lotes[^\n]*/mu', count($itens) === 1
                ? '1.3. A licitação será em item único, conforme o Termo de Referência (Anexo I).'
                : '1.3. A licitação será dividida em '.count($itens).' itens, conforme o Termo de Referência (Anexo I), e cada item pode ser disputado e adjudicado separadamente.', $t);
        }

        // 3.4: tratamento diferenciado das ME/EPP, item a item.
        $t = $this->inserirDepois($t, '3.3.', $itens === []
            ? '3.4. Tratamento diferenciado às microempresas e empresas de pequeno porte, por item: [informe o benefício de cada item (art. 4º da Lei 14.133/2021 e arts. 47 a 49 da LC 123/2006)].'
            : "3.4. Tratamento diferenciado às microempresas e empresas de pequeno porte, por item (art. 4º da Lei 14.133/2021 e arts. 47 a 49 da LC 123/2006):\n".implode("\n", array_map(
                fn ($i) => "Item {$i['numero']}, ".rtrim(trim($i['descricao']), '.').': '.(self::BENEFICIOS[$i['beneficio']] ?? '[informe o benefício]').'.', $itens)));

        // 4.1: modo de disputa, data, hora e local da sessão.
        if (! empty($d['modo_disputa'])) {
            $t = preg_replace('/\(modo de disputa \[[^\]]*\]\)/u', '(modo de disputa '.mb_strtolower($d['modo_disputa']).')', $t);
        }
        $sessao = $this->data($d['sessao_em'] ?? null);
        $quando = $sessao ? 'em '.$sessao->format('d/m/Y').', às '.$sessao->format('H\hi').' (horário de Brasília)' : 'em [informe a data e a hora da sessão]';
        $t = preg_replace('/na data e hora indicadas( no aviso)?/u', $quando, $t);
        if (($local = trim((string) ($d['local'] ?? ''))) !== '') {
            $t = preg_replace('/no endereço \[informe\]/u', 'no endereço '.$local, $t);
            $t = preg_replace('/em plataforma eletrônica(?! \()/u', "em plataforma eletrônica ({$local})", $t, 1);
        }
        if ($sessao && ($prazo = $this->prazoMinimo((bool) ($d['bens'] ?? false), (int) ($d['criterio'] ?? 1)))) {
            $limite = $this->dias->subtrair($sessao, $prazo['dias']);
            $alertas[] = "Divulgue o edital no PNCP até {$limite->format('d/m/Y')}: são pelo menos {$prazo['dias']} dias úteis entre a divulgação e a abertura ({$prazo['base']}).";
        }

        // 5.3: exigências de habilitação próprias desta licitação.
        $hab = trim((string) ($d['habilitacao'] ?? ''));
        $t = $this->inserirDepois($t, '5.2.', '5.3. Exigências de habilitação desta licitação, conforme o Termo de Referência (Anexo I): '
            .($hab !== '' ? $hab : '[informe as exigências de qualificação técnica e econômico-financeira proporcionais ao objeto (arts. 67 e 69)]'));

        // 8.1 e 8.2: prazo para assinar o contrato e garantia.
        $prazoAss = (int) ($d['prazo_assinatura'] ?? 0);
        if ($prazoAss > 0) {
            $t = preg_replace('/em até \[informe\] dias úteis/u', "em até {$prazoAss} dias úteis contados da convocação, prorrogável uma vez por igual período, a pedido justificado (art. 90, § 1º)", $t);
        }
        $grandeVulto = ! empty($d['grande_vulto_engenharia']);
        $pct = $d['garantia_pct'] ?? null;
        if ($pct !== null) {
            $motivos = $this->garantia->validar((float) $pct, $d['garantia_justificativa'] ?? null, $grandeVulto);
            if ($motivos !== []) {
                array_push($alertas, ...$motivos);
            } else {
                $t = preg_replace('/Garantia de execução: \[[^\]]*\]/u', 'Garantia de execução: '.$this->textoGarantia((float) $pct, (string) ($d['garantia_justificativa'] ?? ''), $grandeVulto), $t);
            }
        }

        // Obras: regime de execução, orçamento, visita técnica e seguro-garantia.
        if (($regime = self::REGIMES[$d['regime'] ?? ''] ?? null) !== null) {
            $t = preg_replace('/no regime de \[informe\]/u', "no regime de {$regime}", $t);
        }
        $t = preg_replace('/constam do Anexo \[informe\]/u', 'constam do Anexo I (Termo de Referência ou projeto básico)', $t);
        $visita = $d['visita'] ?? null;
        if ($visita === 'facultativa') {
            $t = preg_replace('/é \[facultativa ou obrigatória[^\]]*\]/u', 'é facultativa', $t);
        } elseif ($visita === 'obrigatoria' && ($jv = trim((string) ($d['visita_justificativa'] ?? ''))) !== '') {
            $t = preg_replace('/é \[facultativa ou obrigatória[^\]]*\]/u', "é obrigatória, porque {$jv}", $t);
        } elseif ($visita === 'obrigatoria') {
            $alertas[] = 'Visita técnica obrigatória exige justificativa no edital (art. 63, § 2º).';
        }
        $t = preg_replace('/^(5-A\.3\. )Seguro-garantia: \[informe\][^\n]*/mu', $grandeVulto
            ? '$1Por se tratar de obra de grande vulto, o seguro-garantia com cláusula de retomada segue o item 8.2 deste edital (arts. 99 e 102).'
            : '$1Não se exige seguro-garantia com cláusula de retomada, próprio das obras e serviços de engenharia de grande vulto (arts. 99 e 102).', $t);

        // 10.2: anexos que saem juntos no caderno do edital.
        $t = preg_replace('/^10\.2\. Integram este edital:[^\n]*/mu', '10.2. Integram este edital: '.implode('; ', array_map(fn ($n, $r) => "Anexo {$n}, {$r}", array_keys(self::ANEXOS), self::ANEXOS)).'.', $t);

        if (($cidade = trim((string) ($d['cidade'] ?? ''))) !== '') {
            $t = preg_replace('/^\[Local\], /mu', "{$cidade}, ", $t);
        }

        return ['texto' => $t, 'pendentes' => self::pendentes($t), 'alertas' => $alertas];
    }

    /** Trechos ainda entre colchetes, para o servidor completar antes de concluir a peça. @return list<string> */
    public static function pendentes(string $texto): array
    {
        preg_match_all('/\[([^\[\]\n]{2,240})\]/u', $texto, $m);

        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /**
     * Prazo mínimo entre a divulgação do edital e a abertura das propostas (art. 55).
     *
     * @return array{dias: int, base: string}|null
     */
    public function prazoMinimo(bool $bens, int $criterio): ?array
    {
        $precoOuDesconto = in_array($criterio, [1, 2], true);

        return match (true) {
            $criterio === 5 => ['dias' => 15, 'base' => 'art. 55, III'],
            in_array($criterio, [3, 4], true) => ['dias' => 35, 'base' => 'art. 55, IV'],
            $bens => $precoOuDesconto ? ['dias' => 8, 'base' => 'art. 55, I, a'] : ['dias' => 15, 'base' => 'art. 55, I, b'],
            $precoOuDesconto => ['dias' => 10, 'base' => 'art. 55, II, a; se o serviço ou a obra for especial, são 25 dias úteis, alínea b'],
            default => ['dias' => 35, 'base' => 'art. 55, II, d'],
        };
    }

    /**
     * Lê a proposta da IA para os campos que faltam. Só volta o que passa nas regras: prazo de 1 a 30 dias úteis,
     * garantia dentro dos limites dos arts. 96 a 99, regime do art. 46. O resto é descartado.
     *
     * @return array<string, mixed>
     */
    public function lerProposta(string $resposta, bool $grandeVulto = false): array
    {
        if (! preg_match('/\{.*\}/s', $resposta, $m) || ! is_array($x = json_decode($m[0], true))) {
            return [];
        }
        $texto = fn ($v, int $max) => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, $max) : null;
        $r = [];

        if ($h = $texto($x['habilitacao'] ?? null, 4000)) {
            $r['habilitacao'] = $h;
        }
        if (is_numeric($x['prazo_assinatura'] ?? null) && (int) $x['prazo_assinatura'] >= 1 && (int) $x['prazo_assinatura'] <= 30) {
            $r['prazo_assinatura'] = (int) $x['prazo_assinatura'];
        }
        if (is_numeric($x['garantia_pct'] ?? null)) {
            $pct = round((float) $x['garantia_pct'], 2);
            $just = $texto($x['garantia_justificativa'] ?? null, 1000);
            if ($this->garantia->validar($pct, $just, $grandeVulto) === [] && $pct >= 0) {
                $r['garantia_pct'] = $pct;
                if ($just) {
                    $r['garantia_justificativa'] = $just;
                }
            }
        }
        if (isset(self::REGIMES[$x['regime'] ?? ''])) {
            $r['regime'] = $x['regime'];
        }
        if (in_array($x['visita'] ?? null, ['facultativa', 'obrigatoria'], true)) {
            $r['visita'] = $x['visita'];
            if ($jv = $texto($x['visita_justificativa'] ?? null, 1000)) {
                $r['visita_justificativa'] = $jv;
            }
        }

        return $r;
    }

    /** Procura o regime do art. 46 citado num texto (ETP ou TR). */
    public static function regimeNoTexto(string $texto): ?string
    {
        $t = mb_strtolower($texto);
        // Do mais específico para o mais genérico: "semi-integrada" contém "integrada".
        foreach (['semi_integrada', 'integrada', 'preco_unitario', 'preco_global', 'integral', 'tarefa', 'fornecimento_servico'] as $k) {
            $rotulo = self::REGIMES[$k];
            // Texto com opções entre colchetes ("[empreitada por preço global, unitário...]") não é uma escolha.
            if (str_contains($t, $rotulo) && ! preg_match('/\[[^\]]*'.preg_quote($rotulo, '/').'/u', $t)) {
                return $k;
            }
        }

        return null;
    }

    /** Percentual de garantia escrito na minuta do contrato (cláusula de garantia), ou 0 se "não exigida". */
    public static function garantiaNoContrato(string $texto): ?float
    {
        if (! preg_match('/CLÁUSULA \d+ª, DA GARANTIA[^\n]*\n([^\n]+)/u', $texto, $m) || str_contains($m[1], '[')) {
            return null;
        }
        if (preg_match('/não (é )?exigida/iu', $m[1])) {
            return 0.0;
        }

        return preg_match('/(\d{1,2}(?:,\d{1,2})?)\s*%/u', $m[1], $p) ? (float) str_replace(',', '.', $p[1]) : null;
    }

    private function textoGarantia(float $pct, string $justificativa, bool $grandeVulto): string
    {
        if ($pct == 0.0) {
            return 'não exigida (art. 96).';
        }
        $n = rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',');
        $base = $grandeVulto
            ? "{$n}% do valor inicial do contrato, na modalidade seguro-garantia com cláusula de retomada (arts. 99 e 102)."
            : "{$n}% do valor inicial do contrato, em caução em dinheiro ou em títulos da dívida pública, seguro-garantia ou fiança bancária, à escolha do contratado (art. 96, § 1º, e art. 98).";

        return $pct > 5 && ! $grandeVulto ? $base." Percentual acima de 5% justificado pela complexidade técnica e pelos riscos: {$justificativa} (art. 98, parágrafo único)." : $base;
    }

    /** Insere um parágrafo depois da linha numerada (como "3.3."), se ela existir e o parágrafo ainda não estiver lá. */
    private function inserirDepois(string $t, string $numero, string $paragrafo): string
    {
        [$sec, $sub] = explode('.', rtrim($numero, '.'));
        $novo = $sec.'.'.((int) $sub + 1).'.';
        if (preg_match('/^'.preg_quote($novo, '/').' /mu', $t)) {
            return $t;
        }

        return preg_replace('/^('.preg_quote($numero, '/').' [^\n]*)/mu', "$1\n".addcslashes($paragrafo, '\\$'), $t, 1);
    }

    private function data(?string $valor): ?CarbonImmutable
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
