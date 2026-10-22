<p>Examples of a small client that sends the key, retries sensibly and reads every page. Adapt them; none needs a library beyond HTTP. You can also generate a full client from the <a href="/api/v1/openapi.json">OpenAPI document</a>.</p>

<h2>PHP</h2>
<pre><code>&lt;?php
function carecore(string $method, string $path, ?array $body = null, array $query = []): array
{
    $url = getenv('CARECORE_URL') . '/api/v1' . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Authorization: Bearer ' . getenv('CARECORE_KEY'), 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        if ($method === 'POST') { $headers[] = 'Idempotency-Key: ' . ($body['_key'] ?? bin2hex(random_bytes(16))); unset($body['_key']); }
    }
    for ($attempt = 1; ; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => true, CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode(substr((string) $raw, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE)), true);
        if ($status &lt; 300 || ($status &lt; 500 &amp;&amp; $status !== 429 &amp;&amp; $status !== 409) || $attempt &gt;= 5) {
            if ($status &gt;= 300) { throw new RuntimeException("API $status {$json['code']}: {$json['detail']} (request {$json['request_id']})"); }
            return $json ?? [];
        }
        sleep(min(30, 2 ** $attempt));          // back off, then retry with the same Idempotency-Key
    }
}

// Every current member of staff, page by page
$cursor = null;
do {
    $page = carecore('GET', '/people', null, ['kind' =&gt; 'staff', 'limit' =&gt; 100] + ($cursor ? ['cursor' =&gt; $cursor] : []));
    foreach ($page['data'] as $person) { echo $person['first_name'], ' ', $person['last_name'], "\n"; }
    $cursor = $page['page']['next_cursor'];
} while ($cursor);

// Start someone: a stable key means a retry cannot start them twice
$res = carecore('POST', '/employments', ['_key' =&gt; 'candidate-4821', 'first_name' =&gt; 'Ada', 'last_name' =&gt; 'Lovelace',
    'job_title' =&gt; 'Support Worker', 'start_date' =&gt; '2026-12-01']);</code></pre>

<h2>Node.js (18 or later)</h2>
<pre><code>const base = process.env.CARECORE_URL + '/api/v1';
const sleep = (ms) =&gt; new Promise((r) =&gt; setTimeout(r, ms));

export async function carecore(method, path, { body, query, key } = {}) {
  const url = base + path + (query ? '?' + new URLSearchParams(query) : '');
  const headers = { Authorization: `Bearer ${process.env.CARECORE_KEY}`, Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (method === 'POST') headers['Idempotency-Key'] = key ?? crypto.randomUUID();
  for (let attempt = 1; ; attempt++) {
    const res = await fetch(url, { method, headers, body: body ? JSON.stringify(body) : undefined });
    if (res.status === 204) return null;
    const json = await res.json().catch(() =&gt; ({}));
    if (res.ok) return json;
    const retry = res.status === 429 || res.status === 409 || res.status &gt;= 500;
    if (!retry || attempt &gt;= 5) throw Object.assign(new Error(`${res.status} ${json.code}: ${json.detail}`), { problem: json });
    await sleep((Number(res.headers.get('Retry-After')) || 2 ** attempt) * 1000);
  }
}

// Iterate every page
export async function* all(path, query = {}) {
  let cursor;
  do {
    const { data, page } = await carecore('GET', path, { query: { limit: 100, ...query, ...(cursor ? { cursor } : {}) } });
    yield* data;
    cursor = page.next_cursor;
  } while (cursor);
}

for await (const t of all('/tasks', { status: 'open', kind: 'leaver' })) console.log(t.title, t.due_date);</code></pre>

<h2>Python</h2>
<pre><code>import os, time, uuid, requests

BASE = os.environ['CARECORE_URL'] + '/api/v1'
session = requests.Session()
session.headers.update({'Authorization': 'Bearer ' + os.environ['CARECORE_KEY'], 'Accept': 'application/json'})

def carecore(method, path, body=None, query=None, key=None):
    headers = {}
    if method == 'POST':
        headers['Idempotency-Key'] = key or str(uuid.uuid4())
    for attempt in range(1, 6):
        r = session.request(method, BASE + path, json=body, params=query, headers=headers, timeout=30)
        if r.status_code &lt; 300:
            return r.json() if r.content else None
        if r.status_code in (429, 409) or r.status_code &gt;= 500:
            time.sleep(int(r.headers.get('Retry-After', 2 ** attempt)))
            continue
        p = r.json()
        raise RuntimeError(f"{r.status_code} {p['code']}: {p['detail']} (request {p['request_id']})")
    raise RuntimeError('gave up after 5 attempts')

def pages(path, **query):
    cursor = None
    while True:
        res = carecore('GET', path, query={'limit': 100, **query, **({'cursor': cursor} if cursor else {})})
        yield from res['data']
        cursor = res['page']['next_cursor']
        if not cursor:
            return

# Everyone changed since yesterday, to keep a copy in step
for person in pages('/people', updated_since='2026-11-11T00:00:00Z'):
    print(person['id'], person['first_name'], person['last_name'])</code></pre>

<h2>Reading the event feed safely</h2>
<pre><code>cursor = load_saved_cursor()            # None the very first time
while True:
    res = carecore('GET', '/events', query={'after': cursor, 'limit': 200} if cursor else {'limit': 200})
    for event in res['data']:
        handle(event)                   # must be idempotent: store event['id'] and skip repeats
        cursor = event['cursor']
        save_cursor(cursor)             # only after handling succeeds
    if not res['page']['has_more']:
        time.sleep(30)</code></pre>

<h2>Verifying Care Core’s responses in your tests</h2>
<ul>
<li>Use the demo (<code>/login</code>) with a throwaway key rather than a live installation.</li>
<li>Assert on <code>code</code> in errors, not on <code>detail</code> text.</li>
<li>Test the failure paths: expired key (<code>401</code>), missing scope (<code>403</code>), a rule refusing a change (<code>422</code>), and a repeated <code>Idempotency-Key</code>.</li>
</ul>
