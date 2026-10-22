<p>The Care Core API lets other systems read and change the same records as the web interface: people, staff, teams, people we support, compliance records, tasks, events and more. It is a JSON API over HTTPS, versioned under <code>/api/v1</code>, authenticated with an API key you control.</p>
<div class="note"><strong>One system of record, one set of rules.</strong> Every change made through the API goes through the same services as the web interface. The same checks apply, a leaver is still one all-or-nothing transaction, and the activity log and outgoing events are written exactly as if a person had clicked the button. The API cannot do anything the web interface would refuse.</div>

<h2>What you can do</h2>
<div class="tablewrap"><table>
<thead><tr><th>Area</th><th>Examples</th></tr></thead>
<tbody>
<tr><td><strong>People</strong></td><td>Search, read, create and update people; sync everyone changed since a time.</td></tr>
<tr><td><strong>Staff</strong></td><td>Start someone (joiner), change role or team (mover), preview and record leaving (leaver), rehires, employment terms (TUPE, working time, visa, apprenticeship, leave), the personal profile (with encrypted National Insurance and bank details behind their own scope).</td></tr>
<tr><td><strong>Compliance</strong></td><td>Registrations, supervisions, appraisals and learning; what is overdue or due soon.</td></tr>
<tr><td><strong>Teams</strong></td><td>Create and nest teams; who is in a team now or on any past date; add and end places.</td></tr>
<tr><td><strong>Care</strong></td><td>Support episodes, care needs, contacts, key workers.</td></tr>
<tr><td><strong>Tasks</strong></td><td>Read the follow-up work that joiners, movers and leavers create, and mark it done.</td></tr>
<tr><td><strong>Events and webhooks</strong></td><td>Poll a feed of changes, or manage webhook endpoints.</td></tr>
<tr><td><strong>Audit and administration</strong></td><td>Read the activity log, the logins list and the consistency checks.</td></tr>
</tbody></table></div>
<p>The <a href="/docs/api-reference">API reference</a> lists every endpoint, and the machine-readable <a href="/api/v1/openapi.json">OpenAPI 3.1 document</a> can generate a client in most languages or be loaded into Postman, Insomnia or Swagger tools.</p>

<h2>Quick start</h2>
<ol>
<li>An administrator opens <strong>Admin › Integrations › API keys</strong>, names the key after the system that will use it, ticks only the scopes it needs, and chooses <strong>Create API key</strong>. The key is shown <strong>once</strong>: copy it.</li>
<li>Check it works:
<pre><code>curl https://YOUR-CARE-CORE/api/v1/ping \
  -H "Authorization: Bearer cc_1a2b3c4d_…"</code></pre>
<pre><code>{ "data": {
    "organisation": { "id": "c0a8…", "name": "Loch View Care" },
    "key": { "name": "Payroll sync", "scopes": ["people.read", "staff.read"], "rate_limit_per_minute": 120, "expires_at": null },
    "server_time": "2026-11-12T09:30:00Z", "api_version": "v1" } }</code></pre></li>
<li>List the current staff:
<pre><code>curl "https://YOUR-CARE-CORE/api/v1/people?kind=staff&amp;limit=50" -H "Authorization: Bearer $KEY"</code></pre></li>
<li>Start someone:
<pre><code>curl -X POST https://YOUR-CARE-CORE/api/v1/employments \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -H "Idempotency-Key: candidate-4821" \
  -d '{"first_name":"Ada","last_name":"Lovelace","email":"ada@example.org",
       "job_title":"Support Worker","start_date":"2026-12-01","hours_per_week":37.5}'</code></pre></li>
</ol>

<h2>Design in brief</h2>
<ul>
<li><strong>Plain REST and JSON.</strong> Resources have stable addresses; verbs mean what you expect.</li>
<li><strong>UUIDs for every id.</strong> Internal numbers never appear.</li>
<li><strong>Scoped keys.</strong> Each key carries only the permissions it needs, can be tied to addresses, can expire, and can be revoked instantly.</li>
<li><strong>Safe retries.</strong> POSTs accept an <code>Idempotency-Key</code>, so a retry after a timeout never starts the same person twice.</li>
<li><strong>Predictable errors.</strong> RFC 9457 problem documents with a stable <code>code</code>, field-level errors and a request id.</li>
<li><strong>Syncing made easy.</strong> <code>updated_since</code> on people, cursor-paged feeds for events and the audit log.</li>
<li><strong>Nothing hidden.</strong> The API is documented from the same route table it runs from, so the reference cannot drift.</li>
</ul>
<p>Next: <a href="/docs/api-auth">Keys, scopes and authentication</a>.</p>
