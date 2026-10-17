import http from 'k6/http';
import { check } from 'k6';

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8080').replace(/\/$/, '');
const benchmarkCase = __ENV.CASE || 'json';
const vus = Number(__ENV.VUS || '1');
if (!['json', 'select'].includes(benchmarkCase)) {
    throw new Error('CASE must be json or select.');
}
if (!Number.isInteger(vus) || vus < 1) {
    throw new Error('VUS must be a positive integer.');
}

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
    const path = benchmarkCase === 'json' ? '/json' : '/select/42';
    const response = http.get(baseUrl + path, {
        redirects: 0,
        timeout: '10s',
        tags: { case: benchmarkCase },
    });
    check(response, {
        'status is 200': (result) => result.status === 200,
        'content type is JSON': (result) => result.headers['Content-Type'] === 'application/json',
        'payload is exact': (result) => result.body === '{"id":42,"value":"record-42"}',
        'no session cookie': (result) => !Object.keys(result.headers).some((name) => name.toLowerCase() === 'set-cookie'),
    });
}

export function handleSummary(data) {
    return { [__ENV.SUMMARY_PATH || 'stdout']: JSON.stringify(data, null, 2) + '\n' };
}
