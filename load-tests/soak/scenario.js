import http from 'k6/http';
import crypto from 'k6/crypto';
import exec from 'k6/execution';
import { check } from 'k6';
import { Trend } from 'k6/metrics';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:19003').replace(/\/$/, '');
const hashes = JSON.parse(__ENV.FILE_HASHES || '{}');
const rate = Number(__ENV.RATE || '10');
if (!Number.isSafeInteger(rate) || rate < 1 || !hashes['1048576'] || !hashes['16777233']) {
    throw new Error('Set RATE and FILE_HASHES from AttachmentFiles::prepare.');
}
const phpBytes = new Trend('php_allocated_bytes');
const phpPeak = new Trend('php_peak_bytes');
const seenWorkers = new Set();

export const options = {
    scenarios: {
        mixed: {
            executor: 'constant-arrival-rate', rate, timeUnit: '1s',
            duration: __ENV.DURATION || '15m', preAllocatedVUs: 4, maxVUs: 12,
        },
    },
    summaryTrendStats: ['avg', 'min', 'max', 'p(50)', 'p(95)', 'p(99)'],
    thresholds: { checks: ['rate==1'], http_req_failed: ['rate==0'], dropped_iterations: ['count==0'] },
};

// Deliberate application errors remain checked outcomes, not transport failures.
http.setResponseCallback(http.expectedStatuses(200, 204, 205, 400, 404, 405, 500));

function parameters(id, operation, binary = false, extraHeaders = {}) {
    return {
        timeout: '10s', redirects: 0, responseType: binary ? 'binary' : 'text',
        headers: { 'X-Request-ID': id, ...extraHeaders }, tags: { operation },
    };
}

function observe(response, expected, id) {
    const pid = response.headers['X-Soak-Pid'];
    check(response, {
        'expected status': (r) => r.status === expected,
        'request ID belongs to this request': (r) => r.headers['X-Request-Id'] === id || r.headers['X-Request-ID'] === id,
        'worker identity and counter': (r) => /^[1-9][0-9]*$/.test(pid || '') && Number(r.headers['X-Soak-Requests']) > 0,
        'PHP memory telemetry is present': (r) => Number(r.headers['X-Soak-Php-Bytes']) > 0
            && Number(r.headers['X-Soak-Php-Peak']) >= Number(r.headers['X-Soak-Php-Bytes']),
        'no session cookie': (r) => !r.headers['Set-Cookie'],
    });
    if (pid && !seenWorkers.has(pid)) {
        console.log(`worker ${pid}`);
        seenWorkers.add(pid);
    }
    phpBytes.add(Number(response.headers['X-Soak-Php-Bytes'] || 0));
    phpPeak.add(Number(response.headers['X-Soak-Php-Peak'] || 0));
    return response;
}

function request(method, path, body, expected, id, binary = false, extraHeaders = {}) {
    const operation = path.startsWith('/select/') ? '/select/{id}'
        : path.startsWith('/__wire/items/') ? '/__wire/items/{id}' : path.split('?')[0];
    return observe(http.request(method, baseUrl + path, body, parameters(id, operation, binary, extraHeaders)), expected, id);
}

export default function () {
    const iteration = exec.scenario.iterationInTest;
    const slot = iteration % 25;
    const id = `soak-${__VU}-${iteration}`;
    if (slot < 6) {
        // A small burst exercises both workers instead of continually reusing the first idle one.
        const records = Array.from({ length: 4 }, (_, offset) => (iteration + offset) % 10000 + 1);
        const responses = http.batch(records.map((record, offset) => ({
            method: 'GET', url: `${baseUrl}/select/${record}`,
            params: parameters(`${id}-${offset}`, '/select/{id}'),
        })));
        responses.forEach((response, offset) => {
            const record = records[offset];
            observe(response, 200, `${id}-${offset}`);
            check(response, { 'SQL record is exact': (r) => r.body === JSON.stringify({ id: record, value: `record-${record}` }) });
        });
    } else if (slot < 10) {
        const response = request('GET', '/json', null, 200, id);
        check(response, { 'JSON record is exact': (r) => r.body === '{"id":42,"value":"record-42"}' });
    } else if (slot < 12) {
        const body = JSON.stringify({ message: id });
        const response = request('POST', slot === 10 ? '/__wire/echo' : '/api/echo', body, 200, id, false, { 'Content-Type': 'application/json' });
        check(response, { 'Echo is exact': (r) => r.body === body });
    } else if (slot < 15) {
        const first = request('GET', `/__wire/items/${iteration}?tag=${id}`, null, 200, id);
        const next = request('GET', '/__wire/context', null, 200, id + '-next');
        check(first, { 'route and query belong to this request': (r) => r.json().params.id === String(iteration) && r.json().queryParams.tag === id });
        check(next, { 'next request has no previous params': (r) => Object.keys(r.json().params).length === 0 && Object.keys(r.json().queryParams).length === 0 });
    } else if (slot < 17) {
        const status = slot === 15 ? 204 : 205;
        const response = request('GET', `/__wire/status/${status}`, null, status, id);
        // k6 represents 204 without a body as null; raw wire checks cover forbidden bytes separately.
        check(response, { 'forbidden body is absent': (r) => r.body === '' || r.body === null });
    } else if (slot < 20) {
        const size = slot === 17 ? 1048576 : 16777233;
        const method = slot === 18 ? 'HEAD' : 'GET';
        const response = request(method, `/__wire/attachment/${size}`, null, 200, id, true);
        check(response, {
            'file size is exact': (r) => r.headers['Content-Length'] === String(size),
            'file content is exact': (r) => r.body !== null && (method === 'HEAD'
                ? r.body.byteLength === 0 : r.body.byteLength === size && crypto.sha256(r.body, 'hex') === hashes[String(size)]),
        });
    } else if (slot === 20) {
        request('POST', '/__wire/echo', '{', 400, id, false, { 'Content-Type': 'application/json' });
    } else if (slot === 21) {
        request('POST', '/__wire/static', null, 405, id);
    } else if (slot === 22) {
        request('GET', '/__wire/missing', null, 404, id);
    } else if (slot === 23) {
        const response = request('GET', '/__wire/failure', null, 500, id);
        check(response, { 'error details stay private': (r) => !r.body.includes('wire-private-exception-details') });
    } else {
        const response = request('GET', '/__wire/input', null, 200, id);
        check(response, { 'input state is empty': (r) => r.json().parsed === null && r.json().upload === null && Object.keys(r.json().cookies).length === 0 });
    }
}

export function handleSummary(data) {
    return { [__ENV.SUMMARY_PATH || 'stdout']: JSON.stringify(data, null, 2) + '\n' };
}
