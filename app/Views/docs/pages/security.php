<p>Care Core holds personal information about staff and about people who receive care. This page explains how it is protected, who can see what, and what remains your organisation’s responsibility. A shorter public summary is on the <a href="/security">Security and privacy</a> page.</p>

<h2>Who can see what</h2>
<ul>
<li>Access is by <a href="/docs/roles">role</a>, checked on the server for every page and action, not just hidden in the menu.</li>
<li>Every query is limited to the signed-in person’s organisation. One organisation cannot reach another’s records. This is tested.</li>
<li><strong>Care information</strong> (needs, contacts, key workers, NHS numbers) is available only to managers and administrators, and only when the Care module is on.</li>
<li><strong>National Insurance and bank details</strong> are shown only on the manager’s staff record page and, masked, to the person themselves and in their own pending requests.</li>
<li>A <strong>signature</strong> can be seen by the person, their line manager, managers and administrators.</li>
</ul>

<h2>Protecting data</h2>
<ul>
<li><strong>Encryption of the most sensitive fields.</strong> National Insurance number and bank details are encrypted in the database with AES-256-GCM, using a key (<code>APP_KEY</code>) that is not in the database. A copy of the database alone does not reveal them. <strong>Keep the key backed up separately, and do not change it:</strong> data encrypted with a lost key cannot be recovered.</li>
<li><strong>Passwords</strong> are stored only as salted hashes (PHP <code>password_hash</code>). Nobody, including administrators, can read them.</li>
<li><strong>Invitation links, SCIM tokens</strong> are stored only as SHA-256 hashes and shown once.</li>
<li><strong>Webhook secrets</strong> are shown once when created. (They are kept in the database because Care Core needs them to sign each delivery, so protect database access and backups.)</li>
<li><strong>Photos and signatures</strong> are stored outside the web root, with random names, are checked to be real images, and are served only to people allowed to see them.</li>
<li><strong>HTTPS</strong> is required on a live site; the server tells browsers to insist on it.</li>
</ul>

<h2>The API</h2>
<ul>
<li>Every API key has <strong>scopes</strong> (the least it needs), an optional <strong>address allow-list</strong> and <strong>expiry</strong>, and its own <strong>rate limit</strong>. Only a hash of the key is stored; it is shown once.</li>
<li>The API is <strong>HTTPS only</strong>, sets no cookies, has no session and sends no CORS headers: keys belong on servers.</li>
<li>Without <code>care.read</code> a key cannot see people who only receive support; National Insurance and bank details need their own scope.</li>
<li>Everything a key does is attributed to it in the <a href="/docs/admin-activity">activity log</a>, and the log never contains sensitive values.</li>
<li>A refused request changes nothing, and unknown ids from another organisation look exactly like ids that do not exist.</li>
</ul>

<h2>Protecting access</h2>
<ul>
<li>Sign-in is rate limited and gives the same message whether or not an account exists.</li>
<li>Sessions use private, script-proof cookies, a new session identifier at each sign-in, and are re-checked on every request. A leaver’s access ends on the right day with no job needing to run.</li>
<li><a href="/docs/entra-signin">Microsoft sign-in</a> verifies tokens fully and matches on a permanent identifier. It never creates accounts.</li>
<li>Every form that changes data carries a secret token tied to your session, so another website cannot make your browser submit one.</li>
<li>Everything displayed is escaped, so a name containing code is shown as text.</li>
<li>Browsers are told to allow only Care Core’s own scripts and styles, to refuse framing the site in another site, and not to guess file types.</li>
<li>All database queries use bound parameters.</li>
</ul>

<h2>Accountability</h2>
<ul>
<li>The <a href="/docs/admin-activity">activity log</a> records who did what and when, in the same transaction as the change. For sensitive fields it names the field, never the value.</li>
<li>Approvals record who reviewed and when. Staff changes to the most sensitive details need a second person.</li>
<li>Events sent to other systems carry identifiers and dates only: no names, contact details or health information.</li>
</ul>

<h2>Protecting the integrity of the record</h2>
<ul>
<li>Changes are all-or-nothing (database transactions).</li>
<li>Rules stop impossible states: overlapping employment, a key worker who is not staff, a team place before the person joined.</li>
<li>The <a href="/docs/admin-reconcile">consistency check</a> proves the data agrees with itself.</li>
</ul>

<h2>What is still up to you</h2>
<div class="note note--warn"><strong>Care Core is a tool, not a compliance certificate.</strong> You remain the data controller for the information in it. The following are your organisation’s decisions and are not automated:</div>
<ul>
<li><strong>Lawful basis, privacy notices and records of processing</strong> under UK GDPR, and a data protection impact assessment for care information.</li>
<li><strong>Retention and deletion.</strong> Care Core keeps history indefinitely and never deletes a person. Agree retention periods, and use the database to archive or remove records when they expire. There is no automated retention tool yet.</li>
<li><strong>Subject access requests.</strong> There is no export button yet; the data is in the database tables listed in the <a href="/docs/data-model">data model</a>.</li>
<li><strong>Multi-factor authentication</strong> for password sign-in is not built. Using Microsoft sign-in lets you apply your tenant’s multi-factor rules.</li>
<li><strong>Hosting security.</strong> Keep PHP, the database and the server patched; restrict who can reach the server and the database; take and test backups; protect the <code>.env</code> file and <code>APP_KEY</code>.</li>
<li><strong>Who gets an administrator role.</strong> Give it to as few people as possible.</li>
<li><strong>Penetration testing and a formal security review</strong> before storing real data.</li>
</ul>
<p>See <a href="/docs/limits">What it does not do yet</a>.</p>
