// Verificação de acessibilidade com axe-core (WCAG 2.1 A e AA), em navegador real (Chromium via Playwright).
// Uso:
//   node scripts/qualidade/acessibilidade.mjs docs/demo/nexplangov-demo.html        (arquivo da demonstração)
//   BASE=http://localhost:8000 EMAIL=admin.pmvv@nexplangov.local SENHA=... \
//     node scripts/qualidade/acessibilidade.mjs /painel /processos /contratos      (sistema rodando)
// Sai com código 1 se houver violação séria ou crítica.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const axe = readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const alvos = process.argv.slice(2);
if (!alvos.length) {
    console.error('Informe o arquivo HTML ou as rotas a verificar.');
    process.exit(2);
}

const base = process.env.BASE;
const navegador = await chromium.launch();
const pagina = await navegador.newPage();
let graves = 0;

if (base && process.env.EMAIL) {
    await pagina.goto(`${base}/login`);
    await pagina.fill('input[name=email]', process.env.EMAIL);
    await pagina.fill('input[name=password]', process.env.SENHA ?? '');
    await Promise.all([pagina.waitForNavigation(), pagina.click('button[type=submit]')]);
}

async function verificar(rotulo) {
    await pagina.addScriptTag({ content: axe });
    const r = await pagina.evaluate(() => window.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }));
    const sérias = r.violations.filter((v) => ['serious', 'critical'].includes(v.impact));
    graves += sérias.length;
    console.log(`\n${rotulo}: ${r.passes.length} regras atendidas, ${r.violations.length} violações (${sérias.length} sérias ou críticas)`);
    for (const v of r.violations) {
        console.log(`  [${v.impact}] ${v.id}: ${v.help} (${v.nodes.length}x)`);
        for (const n of v.nodes.slice(0, 3)) console.log(`      ${n.target.join(' ')}`);
    }
}

for (const alvo of alvos) {
    if (alvo.endsWith('.html')) {
        const url = pathToFileURL(resolve(alvo)).href;
        await pagina.goto(url);
        await verificar(`${alvo} › entrada`);
        // Demonstração: entra com o primeiro usuário (administrador) e passa por cada tela do menu.
        await pagina.click('[data-acao="entrar"]');
        await pagina.waitForTimeout(200);
        const telas = await pagina.$$eval('nav a[href^="#/"]', (as) => [...new Set(as.map((a) => a.getAttribute('href')))]);
        for (const t of telas) {
            await pagina.goto(url + t);
            await pagina.waitForTimeout(150);
            await verificar(`${alvo} › ${t}`);
        }
    } else {
        await pagina.goto(`${base ?? 'http://localhost:8000'}${alvo}`);
        await verificar(alvo);
    }
}

await navegador.close();
console.log(`\nTotal de violações sérias ou críticas: ${graves}`);
process.exit(graves ? 1 : 0);
