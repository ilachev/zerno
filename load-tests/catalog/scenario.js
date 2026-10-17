import http from 'k6/http';
import { check } from 'k6';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const benchmarkCase = __ENV.CASE || 'list';
const documentId = __ENV.DOCUMENT_ID || '1';
const expectedTitle = __ENV.EXPECTED_TITLE || `Документ №${documentId}`;
const vus = Number(__ENV.VUS || '1');
if (!['list', 'detail'].includes(benchmarkCase)) {
    throw new Error('CASE must be list or detail. File transfers are measured separately with a bounded streaming client.');
}
if (!/^[1-9][0-9]*$/.test(documentId)) {
    throw new Error('DOCUMENT_ID must be a positive integer written as digits.');
}
if (!Number.isInteger(vus) || vus < 1) {
    throw new Error('VUS must be a positive integer.');
}

function escapeHtml(value) {
    return value.replace(/[&<>"']/g, (character) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    })[character]);
}

const title = benchmarkCase === 'list' ? 'Документы' : escapeHtml(expectedTitle);
const path = benchmarkCase === 'list' ? '/documents' : `/documents/${documentId}`;

export const options = {
    vus,
    duration: __ENV.DURATION || '10s',
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(50)', 'p(95)', 'p(99)'],
    thresholds: {
        checks: ['rate==1'],
        http_req_failed: ['rate==0'],
    },
};

http.setResponseCallback(http.expectedStatuses(200));

export default function () {
    const response = http.get(baseUrl + path, {
        redirects: 0,
        timeout: '10s',
        responseType: 'text',
        tags: { case: benchmarkCase },
    });
    check(response, {
        'status is 200': (result) => result.status === 200,
        'content type is HTML': (result) => result.headers['Content-Type'] === 'text/html; charset=utf-8',
        'page title is exact': (result) => typeof result.body === 'string'
            && result.body.includes(`<title>${title} — Каталог документов</title>`),
        'heading is exact': (result) => typeof result.body === 'string' && result.body.includes(`<h1>${title}</h1>`),
        'page contains the expected catalog content': (result) => typeof result.body === 'string' && (benchmarkCase === 'list'
            ? result.body.includes('<ol>') && /href="\/documents\/[1-9][0-9]*"/.test(result.body)
            : result.body.includes('<p class="description">') && result.body.includes(`href="/documents/${documentId}/download"`)),
        'response is not stored': (result) => result.headers['Cache-Control'] === 'no-store',
        'content sniffing is disabled': (result) => result.headers['X-Content-Type-Options'] === 'nosniff',
        'no session cookie': (result) => !Object.keys(result.headers).some((name) => name.toLowerCase() === 'set-cookie'),
    });
}

export function handleSummary(data) {
    return { [__ENV.SUMMARY_PATH || 'stdout']: JSON.stringify(data, null, 2) + '\n' };
}
