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
    'alertas_vigencia' => [120, 90, 60, 30],

    // Prazo de resposta ao titular de dados (LGPD, art. 19, II): 15 dias.
    'lgpd_prazo_dias' => 15,
];
