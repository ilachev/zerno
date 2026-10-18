import http from 'k6/http';
import crypto from 'k6/crypto';
import { check } from 'k6';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const size = Number(__ENV.FILE_SIZE || '1048576');
const expectedHash = __ENV.EXPECTED_SHA256;
const method = __ENV.METHOD || 'GET';
if (!Number.isSafeInteger(size) || size < 0 || !/^[a-f0-9]{64}$/.test(expectedHash || '') || !['GET', 'HEAD'].includes(method)) {
    throw new Error('Set FILE_SIZE, EXPECTED_SHA256 and METHOD=GET|HEAD for a prepared attachment fixture.');
}

export const options = {
    vus: 1,
    duration: __ENV.DURATION || '10s',
    summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(50)', 'p(95)', 'p(99)'],
    thresholds: { checks: ['rate==1'], http_req_failed: ['rate==0'] },
};

http.setResponseCallback(http.expectedStatuses(200));

export default function () {
    const response = http.request(method, `${baseUrl}/__wire/attachment/${size}`, null, {
        redirects: 0,
        timeout: '10s',
        responseType: 'binary',
    });
    check(response, {
        'status is 200': (result) => result.status === 200,
        'size header is exact': (result) => result.headers['Content-Length'] === String(size),
        'body is exact': (result) => result.body !== null && (method === 'HEAD'
            ? result.body.byteLength === 0
            : result.body.byteLength === size && crypto.sha256(result.body, 'hex') === expectedHash),
        'content sniffing is disabled': (result) => result.headers['X-Content-Type-Options'] === 'nosniff',
        'attachment is explicit': (result) => (result.headers['Content-Disposition'] || '').startsWith('attachment;'),
        'no session cookie': (result) => !Object.keys(result.headers).some((name) => name.toLowerCase() === 'set-cookie'),
    });
}

export function handleSummary(data) {
    return { [__ENV.SUMMARY_PATH || 'stdout']: JSON.stringify(data, null, 2) + '\n' };
}
