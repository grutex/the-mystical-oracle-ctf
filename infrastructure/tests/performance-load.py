"""Synthetic authenticated reads; use only the disposable Compose project on port 9088."""
import concurrent.futures
import http.cookiejar
import json
import time
import urllib.request

BASE = 'http://127.0.0.1:9088/api/api.php?action='

def client(index):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(action, body=None, csrf=''):
        headers = {'Host': 'localhost'}
        if body is not None:
            headers.update({'Content-Type': 'application/json', 'X-CSRF-Token': csrf})
        response = opener.open(urllib.request.Request(BASE + action,
            data=None if body is None else json.dumps(body).encode(), headers=headers), timeout=30)
        return json.loads(response.read())
    csrf = request('bootstrap')['csrf']
    request('login', {'email': f'perf_{index}@example.test', 'password': 'Performance!Test927'}, csrf)
    return request

if __name__ == '__main__':
    with concurrent.futures.ThreadPoolExecutor(max_workers=20) as pool:
        clients = list(pool.map(client, range(120)))
    def read(request):
        result = []
        for action in ('status', 'me', 'leaderboard'):
            start = time.perf_counter()
            response = request(action)
            assert 'contest' in response, (action, response)
            if action == 'me':
                assert response['profile']['login'].startswith('perf_')
            if action == 'leaderboard':
                players = {row['username'] for row in response['rows']}
                assert {f'perf_{i}' for i in range(120)} <= players, 'Missing load-test players in ranking'
            result.append((time.perf_counter() - start) * 1000)
        return result
    start = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=120) as pool:
        times = sorted(t for batch in pool.map(read, clients) for t in batch)
    print(json.dumps({'sessions': 120, 'requests': len(times), 'errors': 0,
        'elapsed_seconds': round(time.perf_counter() - start, 2),
        'p50_ms': round(times[len(times)//2], 1), 'p95_ms': round(times[int(len(times)*.95)], 1),
        'max_ms': round(times[-1], 1)}, indent=2))
