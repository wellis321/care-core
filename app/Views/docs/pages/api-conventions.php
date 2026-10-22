<h2>Base address and format</h2>
<ul>
<li>Base: <code>https://YOUR-CARE-CORE/api/v1</code>. The API root (<code>GET /api/v1</code>) and <code>GET /api/v1/openapi.json</code> need no key.</li>
<li>Send and receive <strong>JSON</strong>. A request with a body needs <code>Content-Type: application/json</code> (otherwise <code>415</code>). The body must be a JSON object (<code>400</code> if it is malformed or a list).</li>
<li><strong>Ids</strong> are UUIDs, in lower case. Anything that is not a valid id, or is not in your organisation, is a <code>404</code>.</li>
<li><strong>Dates</strong> are <code>YYYY-MM-DD</code>, in the organisation’s own calendar (a last day is a date, not a time).</li>
<li><strong>Times</strong> are ISO 8601 in UTC, such as <code>2026-11-12T09:30:00Z</code>.</li>
<li>Responses are never cached (<code>Cache-Control: no-store</code>).</li>
<li>Every response carries <code>X-Request-Id</code>. Quote it if you ask for help.</li>
</ul>

<h2>Responses</h2>
<pre><code>// one thing
{ "data": { "id": "5a41…", "first_name": "Ada", … } }

// a list
{ "data": [ { … }, { … } ],
  "page": { "limit": 50, "next_cursor": "djE6MTIz", "has_more": true } }</code></pre>
<div class="tablewrap"><table>
<thead><tr><th>Status</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td>200</td><td>Success. The body is the resource (or an outcome, for actions such as ending an employment).</td></tr>
<tr><td>201</td><td>Created. <code>Location</code> gives the address of the new resource; the body is the resource.</td></tr>
<tr><td>204</td><td>Done, no body (deletes).</td></tr>
<tr><td>4xx</td><td>Your request was refused: see <a href="/docs/api-errors">Errors</a>. Nothing was changed.</td></tr>
<tr><td>5xx</td><td>Our fault. Retry with the same <code>Idempotency-Key</code>.</td></tr>
</tbody></table></div>

<h2>Updating</h2>
<ul>
<li><code>PATCH</code> changes only the fields you send. Send <code>null</code> to clear an optional field.</li>
<li><strong>Unknown fields are refused</strong> (<code>422</code>, with the field name), so a typo cannot silently do nothing.</li>
<li>Actions that are not simple edits are their own <code>POST</code> endpoints, because they are processes with consequences: <code>POST /employments</code> (start), <code>POST /employments/{id}/changes</code> (move), <code>POST /employments/{id}/end</code> (leave), <code>POST /teams/{id}/close</code>, <code>POST /people/{id}/support/end</code>. They are described by what they do and cannot be undone by a <code>PATCH</code>.</li>
<li>History is kept. Compliance records can be added and removed but not edited; employments and places are ended, never deleted.</li>
</ul>

<h2>Pagination</h2>
<p>Lists are paged with a <strong>cursor</strong>:</p>
<ol>
<li>Ask with <code>limit</code> (1 to 200, default 50).</li>
<li>If <code>page.has_more</code> is true, pass <code>page.next_cursor</code> back as <code>cursor</code> to get the next page. Stop when <code>next_cursor</code> is <code>null</code>.</li>
</ol>
<p>Cursors are opaque: do not build or inspect them. They stay valid and consistent while new records arrive, so you never see a row twice or miss one. Most lists are ordered oldest first (by creation); the event feed and activity log are oldest first so you can keep a cursor and read only what is new; webhook deliveries are newest first.</p>

<h2>Filtering and syncing</h2>
<ul>
<li><code>GET /people</code> filters: <code>q</code> (name or email), <code>email</code>, <code>kind</code> (<code>staff</code>, <code>supported</code>, <code>leavers</code>), <code>employed_on</code> (a date), <code>updated_since</code> (a UTC time).</li>
<li><strong><code>updated_since</code></strong> returns everyone whose record changed at or after that time: the simplest way to keep a copy in step. Pair it with the event feed to learn about changes to employment, teams and so on.</li>
<li>Other lists have their own filters (<code>status</code>, <code>person_id</code>, <code>kind</code>, <code>due_before</code>, <code>types</code> …): see the <a href="/docs/api-reference">reference</a>.</li>
</ul>

<h2>Rate limits</h2>
<p>Each key has its own limit per minute (default 120, set when you create it). Every response says where you stand:</p>
<pre><code>X-RateLimit-Limit: 120
X-RateLimit-Remaining: 117
X-RateLimit-Reset: 1762939860      (Unix time when the minute ends)</code></pre>
<p>Beyond the limit you get <code>429 rate_limited</code> and a <code>Retry-After</code> header (seconds). Wait that long and retry. Prefer <code>updated_since</code> and the event feed over fetching everything repeatedly.</p>

<h2>Idempotent POSTs</h2>
<p>Networks fail. If a <code>POST</code> times out, you do not know whether it happened. Send an <code>Idempotency-Key</code> header (any unique string up to 100 characters, such as an id from your own system) and the retry is safe:</p>
<pre><code>POST /api/v1/employments
Idempotency-Key: candidate-4821</code></pre>
<ul>
<li>The <strong>first</strong> request does the work. A <strong>repeat with the same key and the same request</strong> returns the stored first response (with <code>Idempotent-Replayed: true</code>) and does nothing again.</li>
<li>The <strong>same key with a different request</strong> is refused (<code>422 idempotency_mismatch</code>): use a new key for a new request.</li>
<li>If the first request is still running, a repeat gets <code>409 request_in_progress</code> with <code>Retry-After</code>.</li>
<li>If a request <strong>failed</strong> (any error), the key is released so you can correct it and send again.</li>
<li>Keys are per API key and remembered for 24 hours.</li>
</ul>
<p>Care Core also protects you without a key: starting someone whose email already exists, a second employment that overlaps the first, or a duplicate key worker are refused by the domain rules.</p>

<h2>Versioning and compatibility</h2>
<ul>
<li>The version is in the address: <code>/api/v1</code>.</li>
<li>Within a version Care Core may <strong>add</strong> endpoints, optional request fields, response fields and event types. It will not remove or rename them, or change what they mean. <strong>Ignore fields you do not recognise.</strong></li>
<li>Anything breaking would be a new version (<code>/api/v2</code>), announced well in advance, with the old one kept running for a long overlap.</li>
</ul>

<h2>Things worth knowing</h2>
<ul>
<li><strong>Logins are not created by the API.</strong> Access is controlled by administrators, the joiner process in the web interface or Entra provisioning. <code>GET /users</code> lists logins read-only.</li>
<li><strong>Self-service approvals are not decided by the API.</strong> <code>GET /profile-changes</code> shows what is waiting, but approving or declining needs a person: the line manager or HR.</li>
<li><strong>Photos and signatures</strong> are not available through the API yet.</li>
<li>Timestamps are UTC; dates are plain dates.</li>
</ul>
<p>Next: <a href="/docs/api-reference">API reference</a>.</p>
