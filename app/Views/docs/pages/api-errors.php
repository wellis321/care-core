<p>Every error is a <strong>problem document</strong> (RFC 9457), with content type <code>application/problem+json</code>:</p>
<pre><code>{
  "type": "https://YOUR-CARE-CORE/docs/api-errors#validation_failed",
  "title": "Unprocessable request",
  "status": 422,
  "code": "validation_failed",
  "detail": "The request body has 2 problems.",
  "errors": [
    { "field": "last_name", "message": "This field is required." },
    { "field": "date_of_birth", "message": "Must be a real date in the form YYYY-MM-DD." }
  ],
  "request_id": "9f3c2a54b0e14c43"
}</code></pre>
<ul>
<li>Branch on <code>code</code>, which is stable. <code>detail</code> is for people and may be reworded.</li>
<li><code>errors</code> lists field-level problems where there are any (otherwise it is empty).</li>
<li>When a rule of the domain refuses a change (for example “Only current staff can be key workers”), the message is in <code>detail</code>. The same text appears in the web interface.</li>
<li><strong>A refused request changes nothing.</strong> Changes are all-or-nothing.</li>
<li>Quote <code>request_id</code> if you ask for help.</li>
</ul>

<h2>Codes</h2>
<div class="tablewrap"><table>
<thead><tr><th>Status</th><th>Code</th><th>Meaning and what to do</th></tr></thead>
<tbody>
<tr id="bad_request"><td>400</td><td><code>bad_request</code></td><td>The request itself is malformed: invalid JSON, a body that is not an object, a bad query parameter (<code>limit</code>, <code>cursor</code>, a date, an unknown <code>kind</code>…). Fix the request.</td></tr>
<tr id="unauthorized"><td>401</td><td><code>unauthorized</code></td><td>The key is missing, invalid, revoked or expired. Do not retry until you have a valid key.</td></tr>
<tr id="forbidden"><td>403</td><td><code>forbidden</code></td><td>The key lacks the scope, or is not allowed from this address. The detail says which.</td></tr>
<tr id="https_required"><td>403</td><td><code>https_required</code></td><td>Use HTTPS. Never send a key over plain HTTP.</td></tr>
<tr id="not_found"><td>404</td><td><code>not_found</code></td><td>No such endpoint, or no such resource <em>visible to this key</em> (it may exist but be in another organisation, or need a scope the key lacks). The API does not reveal which.</td></tr>
<tr id="method_not_allowed"><td>405</td><td><code>method_not_allowed</code></td><td>Wrong method for that address. The <code>Allow</code> header lists the right ones.</td></tr>
<tr id="request_in_progress"><td>409</td><td><code>request_in_progress</code></td><td>A request with that <code>Idempotency-Key</code> is still being processed. Wait for <code>Retry-After</code>, then repeat.</td></tr>
<tr id="unsupported_media_type"><td>415</td><td><code>unsupported_media_type</code></td><td>Send <code>Content-Type: application/json</code>.</td></tr>
<tr id="validation_failed"><td>422</td><td><code>validation_failed</code></td><td>The request was understood but refused: a field is missing or wrong (see <code>errors</code>), or a rule of the domain forbids it (see <code>detail</code>). Change the request. Retrying unchanged will fail the same way.</td></tr>
<tr id="idempotency_mismatch"><td>422</td><td><code>idempotency_mismatch</code></td><td>That <code>Idempotency-Key</code> was already used for a different request.</td></tr>
<tr id="rate_limited"><td>429</td><td><code>rate_limited</code></td><td>Too many requests for this key. Wait <code>Retry-After</code> seconds.</td></tr>
<tr id="internal_error"><td>500</td><td><code>internal_error</code></td><td>Our fault. Retry with the same <code>Idempotency-Key</code> after a short delay; if it persists, report the <code>request_id</code>.</td></tr>
</tbody></table></div>

<h2>Retrying safely</h2>
<ul>
<li><strong>Retry</strong> on <code>429</code> (after <code>Retry-After</code>), <code>500</code>, <code>502</code>, <code>503</code>, <code>504</code>, <code>409 request_in_progress</code>, and network errors, with exponential back-off and a limit on attempts.</li>
<li>Always send an <code>Idempotency-Key</code> on <code>POST</code> so that a retry cannot do something twice.</li>
<li><strong>Do not retry</strong> <code>400</code>, <code>401</code>, <code>403</code>, <code>404</code>, <code>405</code>, <code>415</code> or <code>422</code> without changing something.</li>
<li><code>GET</code>, <code>PATCH</code> and <code>DELETE</code> are safe to retry (a <code>DELETE</code> of something already deleted may return a <code>422</code>).</li>
</ul>
