# Design system NexPlanGov

## Marca

- `public/marca/simbolo.svg`: quadrado arredondado com gradiente azul → marinho, anel turquesa aberto (herança NexMind) e três trilhas ascendentes com nós (planejamento que avança por etapas).
- `public/marca/logo-nexplangov.svg` e `logo-nexplangov-branco.svg`: "NexPlan" + "Gov" com a assinatura "POR NEXMIND".
- Rodapé (`x-rodape`): "Desenvolvido por" + logo NexMind com link para https://nexmind.net.br/.

## Cores

| Token | Cor | Uso |
|---|---|---|
| marinho | #1E5470 | títulos, botão primário (fim do gradiente), barra lateral |
| azul | #34729C | ações, links, início do gradiente |
| celeste | #6CB1DA | destaques, foco |
| turquesa | #6EC1D1 | sucesso, anel da marca |
| gelo | #D1ECFF | fundos de destaque, badges |
| água | #CBEAEC | fundos secundários |
| tinta | #0F2A3A | texto principal |
| texto2 | #4A6273 | texto secundário |
| linha | #E1EBF2 | bordas |
| fundo | #F3F7FA | fundo da página |

## Tipografia

Plus Jakarta Sans (400 a 800), hospedada em `public/fonts`. Valores em `tabular-nums`.

## Componentes (resources/css/app.css)

- Botões: `.btn-p` (gradiente com brilho no hover e elevação), `.btn-s` (secundário), `.btn-t` (texto), `.btn-g` (perigo), `.btn-icone`.
- Cartões `.card`, indicadores `.kpi`, tabelas `.tabela`, abas `.tabs`, etapas `.etapas`, checklist `.checklist`, barras de consumo `.barra` (estados alerta e estourou), linha do tempo `.timeline`, citação legal `.lei` e `.aviso-lei`.
- Movimento: tokens `--dur-rapida` e `--dur-media`, curva `--ease-suave`. Tudo desligado com `prefers-reduced-motion`.

## Logo NexMind no rodapé

- `public/marca/nexmind-rodape.png`: fundo transparente, texto em marinho #1E5470 e símbolo em turquesa, para fundos claros (rodapé do sistema). Exibida a 30 px de altura; o arquivo tem o dobro da resolução para telas de alta densidade.
- `public/marca/nexmind-rodape-branco.png`: mesma arte com o texto em branco, para fundos escuros.
