<h2>I forgot my password</h2>
<p>An administrator chooses <strong>New invitation</strong> against your login on Admin › Logins and gives you the link. If you are the only administrator and are locked out, whoever hosts Care Core can issue one from the server: <code>php scripts/reinvite.php you@example.org</code> prints a one-time link.</p>
<h2>I cannot sign in</h2>
<div class="tablewrap"><table>
<thead><tr><th>Message</th><th>What to do</th></tr></thead>
<tbody>
<tr><td>“That email address or password is not right”</td><td>Check both. The message is the same whether the email exists or not. The login must be active (not invited or disabled) and its access end date must not have passed.</td></tr>
<tr><td>“Too many sign-in attempts”</td><td>Wait 15 minutes.</td></tr>
<tr><td>Invitation link “expired or already used”</td><td>Links last 7 days and work once. An administrator creates the login again.</td></tr>
<tr><td>Signed out unexpectedly</td><td>The login’s access end date has passed, it was disabled, or the browser cleared cookies.</td></tr>
<tr><td>Passwords are refused for staff</td><td><code>PASSWORD_LOGIN=false</code> is set. Use Microsoft sign-in.</td></tr>
</tbody></table></div>
<p>Microsoft sign-in problems are listed on <a href="/docs/entra-signin">that page</a>.</p>

<h2>A page says “not switched on”</h2>
<p>An administrator has switched that module off. See <a href="/docs/admin-modules">Modules</a>.</p>

<h2>A page says “Not allowed”</h2>
<p>Your role does not allow it. See <a href="/docs/roles">Roles and permissions</a>.</p>

<h2>“Please try again: your session expired or the form was out of date”</h2>
<p>The form had been open for a long time or in two tabs. Reload the page and repeat.</p>

<h2>A change was refused</h2>
<p>The message says why. The rules are listed in <a href="/docs/rules">Rules that are enforced</a>. Common ones: a key worker must be current staff; a team stint cannot start before the previous one ended; a change of role cannot be in the future.</p>

<h2>Photos or signatures will not upload</h2>
<ul>
<li>It must be a genuine PNG, JPEG, WebP or GIF picture, not larger than 3 MB (signature 1 MB).</li>
<li>If a picture under the limit fails with “too large” or “did not work”, the server’s <code>upload_max_filesize</code> or <code>post_max_size</code> is too small.</li>
<li>If saving fails, <code>storage/uploads</code> may not be writable by the web server.</li>
<li>The drawing box needs JavaScript. Without it, upload a picture of the signature instead.</li>
</ul>

<h2>The images do not show</h2>
<p>Images are served by Care Core after a permission check, not as files. If a photo is missing, the file in <code>storage/uploads</code> may have been lost (restore from a backup) or you do not have permission to see it.</p>

<h2>“APP_KEY is not set”</h2>
<p>Run <code>php scripts/make-key.php</code>. If you moved the site, copy the <em>original</em> key: a new one cannot read data encrypted with the old one.</p>

<h2>A National Insurance or bank value looks blank to managers</h2>
<p>If <code>APP_KEY</code> has changed since it was saved, the value cannot be decrypted. Restore the original key. (Values saved before encryption was introduced remain readable.)</p>

<h2>Webhooks are not arriving</h2>
<ol>
<li>Is the delivery job scheduled to run every minute?</li>
<li>Admin › Integrations: is the address active, and does its event filter include the type?</li>
<li>Is the address <code>https://</code> and reachable from the internet?</li>
<li>Does your receiver return a 2xx within 10 seconds? The page shows waiting or failed counts.</li>
<li>Is your receiver verifying the signature with the <em>raw body</em> and the right secret?</li>
</ol>

<h2>Entra provisioning fails</h2>
<p>Test connection fails: the tenant URL must end in <code>/scim/v2</code> and the token must be active. Individual users fail: <code>userName</code> must be an email; a user who is already employed gets <code>409</code>. See <a href="/docs/scim">Provisioning from Entra</a>.</p>

<h2>The consistency check reports something</h2>
<p>See <a href="/docs/admin-reconcile">Consistency checks</a>. Fix what can be fixed, and decide the rest (a key worker, a team).</p>

<h2>The demo shows odd data or an error after an upgrade</h2>
<p>Run <code>php scripts/demo-reset.php</code> to rebuild it.</p>

<h2>Errors on screen</h2>
<p>With <code>APP_DEBUG=false</code> you see a friendly page and the details are in the server’s error log. Look there first. If you are the developer, set <code>APP_DEBUG=true</code> <em>locally only</em>.</p>
