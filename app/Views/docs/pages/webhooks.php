<p>Every change in Care Core creates an <strong>event</strong>. Webhooks deliver those events to other systems (a rota, a payroll tool, a training platform, your own scripts) as signed HTTP requests, so they can react to a joiner, mover or leaver without polling Care Core and without anyone telling them.</p>

<h2>How it works</h2>
<ol>
<li>When something changes, an event is written to the <strong>outbox</strong> in the <em>same transaction</em> as the change. An event can never be lost, and never exists for a change that rolled back.</li>
<li>A background job (<code>scripts/deliver-events.php</code>, run every minute) matches each new event to the webhook endpoints that want it and sends it.</li>
<li>If the receiver fails, the delivery is <strong>retried</strong> with increasing gaps.</li>
</ol>
<p>Events are delivered <strong>at least once</strong>. Your receiver must therefore tolerate seeing the same event twice, using the event id as an idempotency key.</p>

<h2>Adding a webhook</h2>
<p>Administrators: <strong>Admin › Integrations › Webhooks</strong>. Enter the <strong>URL</strong> (must be <code>https://</code>) and the <strong>event types</strong> you want, then choose <strong>Add</strong>. Care Core shows the <strong>signing secret once</strong>: copy it and store it where your receiver can read it. You can <strong>pause</strong> and resume an endpoint, and the page shows, for each address, how many events were delivered and how many are waiting or failed, plus the latest events and whether each has been queued for delivery.</p>
<h3>Choosing event types</h3>
<ul>
<li><code>*</code> (the default): everything.</li>
<li>A prefix with a star: <code>employment.*</code> matches <code>employment.started</code>, <code>employment.changed</code> and <code>employment.ended</code>.</li>
<li>Exact names, separated by commas: <code>employment.started,employment.ended</code>.</li>
</ul>
<p>The full list is in the <a href="/docs/event-catalogue">event catalogue</a>.</p>
<h3>Address rules</h3>
<p>The address must be https (plain http is allowed only for localhost while developing), must not contain a username or password, and must not point at link-local or internal metadata addresses. This stops a webhook being used to probe your own network.</p>

<h2>What a delivery looks like</h2>
<pre><code>POST /your/endpoint HTTP/1.1
Content-Type: application/json
User-Agent: CareCore-Webhook/1
X-CareCore-Event: employment.ended
X-CareCore-Event-Id: 7f1c2a54-9b0e-4c43-8d1a-3d9f7c1d2e10
X-CareCore-Attempt: 1
X-CareCore-Signature: sha256=3b1d… (hex HMAC)

{
  "id": "7f1c2a54-9b0e-4c43-8d1a-3d9f7c1d2e10",
  "type": "employment.ended",
  "version": 1,
  "occurred_at": "2026-11-12T09:30:00.123Z",
  "organisation": "c0a8…",
  "subject": "5a41…",
  "data": { "employment": "9be2…", "end_date": "2026-11-12", "reason": "Resigned" }
}</code></pre>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><code>id</code></td><td>Unique id of this event. <strong>Use it to de-duplicate.</strong></td></tr>
<tr><td><code>type</code></td><td>What happened, for example <code>employment.ended</code>.</td></tr>
<tr><td><code>version</code></td><td>Envelope version. Currently 1. New fields may be added within a version; existing ones will not change meaning.</td></tr>
<tr><td><code>occurred_at</code></td><td>UTC time with milliseconds.</td></tr>
<tr><td><code>organisation</code></td><td>The organisation’s UUID.</td></tr>
<tr><td><code>subject</code></td><td>The UUID of the <em>person</em> the event is about (or of the team, for team events).</td></tr>
<tr><td><code>data</code></td><td>Identifiers and dates, specific to the type.</td></tr>
</tbody></table></div>
<div class="note"><strong>By design, events contain identifiers and dates only.</strong> No names, no contact details, no health information. A receiver that needs the person’s details looks them up (or you pass them on yourself through a system you control). That keeps personal data out of every log, queue and proxy along the way.</div>

<h2>Verifying the signature</h2>
<p>Always verify the signature before trusting a delivery. It is a hex <strong>HMAC-SHA256</strong> of the <strong>raw request body</strong>, using the endpoint’s secret as the key, sent as <code>sha256=&lt;hex&gt;</code>. Compare in constant time, and use the raw bytes, not a re-encoded copy of the JSON.</p>
<h3>PHP</h3>
<pre><code>$body = file_get_contents('php://input');
$sent = $_SERVER['HTTP_X_CARECORE_SIGNATURE'] ?? '';
$want = 'sha256=' . hash_hmac('sha256', $body, getenv('CARECORE_WEBHOOK_SECRET'));
if (!hash_equals($want, $sent)) { http_response_code(401); exit; }
$event = json_decode($body, true);
// de-duplicate on $event['id'], then act
http_response_code(204);</code></pre>
<h3>Node.js (Express)</h3>
<pre><code>import crypto from 'node:crypto';
app.post('/carecore', express.raw({ type: 'application/json' }), (req, res) =&gt; {
  const want = 'sha256=' + crypto.createHmac('sha256', process.env.SECRET).update(req.body).digest('hex');
  const got = req.get('X-CareCore-Signature') || '';
  if (got.length !== want.length || !crypto.timingSafeEqual(Buffer.from(got), Buffer.from(want))) return res.sendStatus(401);
  const event = JSON.parse(req.body);   // de-duplicate on event.id, then act
  res.sendStatus(204);
});</code></pre>
<h3>Python (Flask)</h3>
<pre><code>import hmac, hashlib, os
from flask import Flask, request, abort
app = Flask(__name__)

@app.post('/carecore')
def carecore():
    body = request.get_data()
    want = 'sha256=' + hmac.new(os.environ['SECRET'].encode(), body, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(want, request.headers.get('X-CareCore-Signature', '')):
        abort(401)
    event = request.get_json()   # de-duplicate on event['id'], then act
    return '', 204</code></pre>

<h2>Responding and retrying</h2>
<ul>
<li>Reply with any <strong>2xx</strong> status within <strong>10 seconds</strong> to acknowledge. Do slow work afterwards, off the request.</li>
<li>Anything else (a timeout, a connection error, a 4xx or 5xx) counts as a failure and is retried after <strong>1 minute, 5 minutes, 30 minutes, 2 hours</strong> and <strong>12 hours</strong>. After the last retry the delivery is marked failed. The <code>X-CareCore-Attempt</code> header says which try this is.</li>
<li>Deliveries to one endpoint are independent. A failing endpoint never holds up the others.</li>
<li>Events are generally delivered in the order they happened, but retries can reorder them. Use <code>occurred_at</code> if order matters, and treat the receiver as idempotent.</li>
</ul>

<h2>Good practice for receivers</h2>
<ul>
<li>Verify the signature first; reject anything that does not verify.</li>
<li>Store processed event ids and ignore repeats.</li>
<li>Acknowledge quickly; queue the real work.</li>
<li>Be ready for event types you do not know yet: ignore them rather than failing.</li>
<li>Keep the secret out of source control. To rotate it, add a new endpoint, switch over, then pause the old one.</li>
</ul>

<h2>Running the delivery job</h2>
<pre><code>* * * * *  php /path/to/scripts/deliver-events.php</code></pre>
<p>Without this job, events accumulate safely in the outbox but nothing is sent. Run it every minute.</p>
