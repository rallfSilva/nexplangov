<?php

return [
    // Administradores (geral e do órgão) só usam o sistema depois de ativar a autenticação em dois fatores.
    'exigir_2fa' => (bool) env('NEXPLANGOV_EXIGIR_2FA', true),

    // Integração com o Portal Nacional de Contratações Públicas (Lei 14.133/2021, art. 174).
    // Todo órgão novo começa em homologação (treina.pncp.gov.br). As credenciais da plataforma
    // ficam só em variáveis de ambiente, nunca no banco.
    'pncp' => [
        'ambiente' => env('PNCP_AMBIENTE', 'homologacao'),
        'urls' => [
            'homologacao' => 'https://treina.pncp.gov.br/api/pncp',
            'producao' => 'https://pncp.gov.br/api/pncp',
        ],
        'login' => env('PNCP_LOGIN'),
        'senha' => env('PNCP_SENHA'),
        'timeout' => (int) env('PNCP_TIMEOUT', 30),
        // O token JWT vale 1 hora; renovamos antes.
        'token_minutos' => 55,
    ],

    // Alertas de vencimento de contrato (dias antes do fim da vigência).
    'alertas_vigencia' => [120, 90, 60, 30, 15, 7],

    // Prazo mínimo (dias úteis) para os participantes se manifestarem em uma IRP (art. 86). O prazo vem do
    // regulamento de cada ente: confirme o do seu e ajuste no .env.
    'irp_prazo_minimo_dias_uteis' => (int) env('NEXPLANGOV_IRP_PRAZO_MINIMO', 8),

    // Prazo (dias corridos) para o órgão aderente contratar depois da autorização da carona, sempre dentro da
    // vigência da ata. A Lei 14.133 deixa ao regulamento; o federal (Decreto nº 11.462/2023) adota 90 dias.
    'carona_prazo_contratacao_dias' => (int) env('NEXPLANGOV_CARONA_PRAZO', 90),

    // Prazo (dias corridos) para pagar depois da liquidação, quando o contrato não fixar outro (art. 92, V).
    'pagamento_prazo_dias' => (int) env('NEXPLANGOV_PRAZO_PAGAMENTO', 30),

    // Validade (dias) do link enviado ao fornecedor para aceitar ou recusar o fornecimento ao aderente.
    'carona_link_aceite_dias' => 15,

    // Feriados estaduais e municipais (Y-m-d, separados por vírgula), somados aos nacionais na contagem de dias úteis.
    'feriados_extras' => array_filter(array_map('trim', explode(',', (string) env('NEXPLANGOV_FERIADOS_EXTRAS', '')))),

    // NexMind IA (assistente de redação e revisão). Desligada por padrão; a chave fica só no .env.
    // O texto vai sem dado pessoal (CPF, e-mail, telefone e CEP são removidos antes do envio).
    'ia' => [
        'habilitada' => (bool) env('NEXPLANGOV_IA', false),
        'chave' => env('ANTHROPIC_API_KEY'),
        'modelo' => env('NEXPLANGOV_IA_MODELO', 'claude-opus-5-5'),
        'esforco' => env('NEXPLANGOV_IA_ESFORCO', 'medium'),
        'limite_diario_por_usuario' => (int) env('NEXPLANGOV_IA_LIMITE_DIARIO', 40),
    ],

    // Turmas de treinamento: órgão fictício que nunca publica no PNCP nem envia e-mail de alerta.
    'treinamento' => [
        'dias' => (int) env('NEXPLANGOV_TREINAMENTO_DIAS', 30),
        'ia' => (bool) env('NEXPLANGOV_TREINAMENTO_IA', false), // libera a NexMind IA nas turmas (cada uso tem custo)
        'dominio_email' => env('NEXPLANGOV_TREINAMENTO_DOMINIO', 'treinamento.nexplangov.local'),
    ],

    // Capacitação: meses em que um curso conta como atualização do agente (art. 7º, II, e art. 173).
    'capacitacao_validade_meses' => (int) env('NEXPLANGOV_CAPACITACAO_MESES', 24),

    // Compras.gov.br, API de dados abertos (pesquisa de preço). Caminhos configuráveis: confirme na documentação vigente.
    'compras_gov' => [
        'url' => env('COMPRASGOV_URL', 'https://dadosabertos.compras.gov.br'),
        'caminho_material' => env('COMPRASGOV_CAMINHO_MATERIAL', '/modulo-pesquisa-preco/1_consultarMaterial'),
        'caminho_servico' => env('COMPRASGOV_CAMINHO_SERVICO', '/modulo-pesquisa-preco/3_consultarServico'),
        'timeout' => (int) env('COMPRASGOV_TIMEOUT', 20),
        'max_paginas' => 3,
    ],

    // SEI: endereço para abrir um processo pelo número (o número entra no lugar de {numero}).
    'sei' => [
        'url_consulta' => env('SEI_URL_CONSULTA'),
    ],

    // Prazo de resposta ao titular de dados (LGPD, art. 19, II): 15 dias.
    'lgpd_prazo_dias' => 15,
];
