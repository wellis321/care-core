<h2>API keys</h2>
<p>An API key identifies one integration. Create them in <strong>Admin › Integrations › API keys</strong> (administrators only).</p>
<div class="tablewrap"><table>
<thead><tr><th>Setting</th><th>Meaning</th></tr></thead>
<tbody>
<tr><td><strong>Name</strong></td><td>The system that will use it, for example “Payroll sync”. It appears in the activity log as <code>system:api:Payroll sync</code> on everything the key does, so you can always tell who made a change.</td></tr>
<tr><td><strong>Scopes</strong></td><td>What the key may do. Tick the least it needs.</td></tr>
<tr><td><strong>Allowed addresses</strong></td><td>Optional. IP addresses or ranges (such as <code>203.0.113.5</code> or <code>198.51.100.0/24</code>, IPv4 or IPv6), separated by commas. The key is refused from anywhere else. Empty means any address.</td></tr>
<tr><td><strong>Expires on</strong></td><td>Optional. The key stops working at the end of that day.</td></tr>
<tr><td><strong>Requests per minute</strong></td><td>10 to 6000. Default 120.</td></tr>
</tbody></table></div>
<p>The key looks like <code>cc_1a2b3c4d_</code> followed by 48 more characters. It is shown <strong>once</strong>, when you create it. Care Core keeps only a one-way hash, so a lost key cannot be recovered: revoke it and create another. The list shows the first part (<code>cc_1a2b3c4d…</code>), the scopes, when it was last used and when it expires.</p>

<h2>Using a key</h2>
<p>Send it as a bearer token on every request:</p>
<pre><code>Authorization: Bearer cc_1a2b3c4d_0123456789abcdef0123456789abcdef0123456789abcdef</code></pre>
<ul>
<li>Always use <strong>HTTPS</strong>. The API refuses plain HTTP on a real host (<code>403 https_required</code>). Only <code>localhost</code> is allowed over HTTP, for development.</li>
<li>Keep keys on your server, in a secrets store or environment variable. <strong>Never put one in a web page, mobile app or code repository.</strong> The API sends no CORS headers, because it is for server-to-server use.</li>
<li>Give each system its own key, so you can revoke one without disturbing the others.</li>
</ul>

<h2>Scopes</h2>
<p>Every endpoint names the scope it needs. A call without it gets <code>403 forbidden</code>, with a message naming the missing scope.</p>
<div class="tablewrap"><table>
<thead><tr><th>Scope</th><th>Allows</th></tr></thead>
<tbody>
<?php foreach (\App\Api\Scopes::ALL as $scope => $desc): ?>
<tr><td><code><?= htmlspecialchars($scope) ?></code></td><td><?= htmlspecialchars($desc) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<h3>Least privilege is built in</h3>
<ul>
<li>Without <code>care.read</code>, people who <em>only receive support</em> are invisible: they are not listed, a direct request for them returns <code>404</code>, and <code>is_supported</code> is <code>null</code>. A payroll system never learns who receives care.</li>
<li>Without <code>people.write</code>, the free-text <code>notes</code> on a person are left out.</li>
<li>National Insurance and bank details are in the profile only for keys with <code>staff.sensitive</code>, and only such keys can change them. They are encrypted at rest, and never written to the activity log or to events.</li>
<li>Webhook secrets are returned once, when the endpoint is created.</li>
</ul>
<p>Any valid key can call <code>GET /ping</code> and <code>GET /scopes</code> (which lists every scope and whether this key has it).</p>

<h2>Rotating and revoking</h2>
<ul>
<li><strong>Rotate:</strong> create a new key with the same scopes, switch the integration over, then revoke the old one. Both work in the meantime.</li>
<li><strong>Revoke</strong> on the same page: the key stops working at once (<code>401</code>).</li>
<li>Set an <strong>expiry</strong> on keys for short-lived projects, and put a reminder in your calendar for the others.</li>
<li>A key that has not been used for a long time shows “never” or an old date under <em>Last used</em>: revoke it.</li>
</ul>

<h2>Authentication errors</h2>
<div class="tablewrap"><table>
<thead><tr><th>Status</th><th>Code</th><th>Cause</th></tr></thead>
<tbody>
<tr><td>401</td><td><code>unauthorized</code></td><td>No <code>Authorization</code> header, a malformed or unknown key, or a revoked or expired key. The response carries <code>WWW-Authenticate: Bearer</code>.</td></tr>
<tr><td>403</td><td><code>forbidden</code></td><td>The key lacks the scope, or is used from an address it is not allowed from.</td></tr>
<tr><td>403</td><td><code>https_required</code></td><td>The request was not sent over HTTPS.</td></tr>
</tbody></table></div>
<p>Every use of a key by a system is attributed to it in the <a href="/docs/admin-activity">activity log</a>. Creating and revoking keys is logged too (never the key itself).</p>
<p>Next: <a href="/docs/api-conventions">Conventions</a>.</p>
