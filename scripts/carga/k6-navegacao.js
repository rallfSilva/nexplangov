// Teste de carga com k6 (https://k6.io): usuários navegando pelas telas mais usadas.
// Prepare dados fictícios antes:  php artisan db:seed --class=DemoSeeder && php artisan nexplangov:carga-demo --processos=2000
// Rode:  k6 run -e BASE=https://homologacao.exemplo.gov.br -e EMAIL=planejamento@nexplangov.local -e SENHA=... scripts/carga/k6-navegacao.js
// Nunca rode contra produção.
import http from 'k6/http';
import { check, group, sleep } from 'k6';

const BASE = __ENV.BASE || 'http://localhost:8000';

export const options = {
    scenarios: {
        navegacao: {
            executor: 'ramping-vus',
            stages: [
                { duration: '1m', target: 20 },
                { duration: '3m', target: 50 },
                { duration: '1m', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{tipo:tela}': ['p(95)<800', 'p(99)<1500'],
    },
};

function token(html) {
    const m = html.match(/name="_token" value="([^"]+)"/);
    return m ? m[1] : '';
}

export function setup() {
    if (!__ENV.EMAIL) throw new Error('Informe EMAIL e SENHA de um usuário de teste.');
}

let logado = false; // cada usuário virtual entra uma vez e mantém a sessão (cookie)

export default function () {
    if (!logado) {
        const pagina = http.get(`${BASE}/login`);
        const r = http.post(`${BASE}/login`, { _token: token(pagina.body), email: __ENV.EMAIL, password: __ENV.SENHA });
        logado = check(r, { 'entrou no sistema': (x) => x.status === 200 && !x.url.endsWith('/login') });
    }

    const telas = ['/painel', '/processos', '/processos?busca=material', '/pca', '/demandas', '/contratos', '/fornecedores', '/atas', '/governanca', '/limite-dispensa'];
    group('navegação', () => {
        for (const t of telas) {
            const r = http.get(`${BASE}${t}`, { tags: { tipo: 'tela' } });
            check(r, { 'abriu a tela': (x) => x.status === 200, 'não caiu no login': (x) => !x.url.endsWith('/login') });
            sleep(Math.random() * 2 + 0.5);
        }
    });
}
