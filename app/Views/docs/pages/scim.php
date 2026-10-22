<p><strong>SCIM</strong> (System for Cross-domain Identity Management) lets Microsoft Entra <em>push</em> people to Care Core, so joiners and leavers in your directory become joiners and leavers here without anyone typing. Care Core implements a SCIM 2.0 <code>Users</code> endpoint.</p>
<div class="note"><strong>Sign-in and provisioning are separate.</strong> <a href="/docs/entra-signin">Sign in with Microsoft</a> lets people sign in. SCIM creates and removes the people. You can use either or both.</div>

<h2>How a SCIM user maps to Care Core</h2>
<p>A SCIM <em>user</em> is a Care Core <strong>person</strong> (its SCIM <code>id</code> is the person’s UUID). The important attribute is <code>active</code>, which means <strong>“currently employed”</strong>:</p>
<div class="tablewrap"><table>
<thead><tr><th>Entra does</th><th>Care Core runs</th></tr></thead>
<tbody>
<tr><td>Creates a user (<code>POST /Users</code>)</td><td>The <a href="/docs/joiners">joiner process</a>: person, employment (starting today), an invited login (which the person can use through Microsoft sign-in) and the new starter checklist. If a person with that email already exists and has left, it is a <strong>rehire</strong>: a new employment for the same person.</td></tr>
<tr><td>Sets <code>active</code> to false, or deletes the user</td><td>The <a href="/docs/leavers">leaver process</a>, taking effect <strong>immediately</strong>: the employment ends (last day yesterday, so access has stopped as of today), team places and key worker links end, tasks are created, and every linked login is <strong>disabled</strong> straight away.</td></tr>
<tr><td>Sets <code>active</code> to true on someone who has left</td><td>A rehire (new employment) and their login is re-enabled.</td></tr>
<tr><td>Changes the name</td><td>First and last name are updated.</td></tr>
<tr><td>Changes the job title</td><td>A change of job title is recorded as a <a href="/docs/movers">mover</a> change from today, with history.</td></tr>
</tbody></table></div>
<p><strong>Care Core owns the rest:</strong> teams, line manager, hours, contract, registrations and everything else are managed in Care Core. Other SCIM attributes are ignored, as the SCIM standard allows. Entra’s <code>externalId</code> is stored as the <strong>employee reference</strong>.</p>
<p>Repeating a request is safe. Removing someone who has already left is not an error.</p>

<h2>Setting it up in Entra</h2>
<ol>
<li>In Care Core, open <strong>Admin › Integrations</strong>. Under SCIM choose <strong>Create token</strong>, give it a name (for example “Microsoft Entra”) and copy the token. <strong>It is shown once.</strong> Care Core keeps only a hash. Note the <strong>tenant URL</strong> shown there (<code>https://YOUR-ADDRESS/scim/v2</code>).</li>
<li>In Entra, go to <strong>Enterprise applications › New application › Create your own application</strong>. Name it “Care Core provisioning” and choose <em>Integrate any other application you don’t find in the gallery</em>.</li>
<li>Open <strong>Provisioning › Get started</strong>, set the mode to <strong>Automatic</strong>, and enter the <strong>Tenant URL</strong> and the <strong>Secret token</strong>. Choose <strong>Test connection</strong>.</li>
<li>Under <strong>Mappings › Provision Microsoft Entra ID Users</strong>, keep these (others can be removed):
<div class="tablewrap"><table>
<thead><tr><th>Entra attribute</th><th>SCIM attribute</th></tr></thead>
<tbody>
<tr><td><code>userPrincipalName</code> (or <code>mail</code>)</td><td><code>userName</code></td></tr>
<tr><td><code>givenName</code></td><td><code>name.givenName</code></td></tr>
<tr><td><code>surname</code></td><td><code>name.familyName</code></td></tr>
<tr><td><code>jobTitle</code></td><td><code>title</code></td></tr>
<tr><td><code>Switch([IsSoftDeleted], …)</code> (default)</td><td><code>active</code></td></tr>
<tr><td><code>mailNickname</code> or an employee id</td><td><code>externalId</code> (the employee reference)</td></tr>
</tbody></table></div></li>
<li>Under <strong>Users and groups</strong>, assign the people (or a group) who should exist in Care Core. Only assigned people are provisioned.</li>
<li>Set <strong>Provisioning status</strong> to <strong>On</strong>. Entra provisions on a cycle of about every 40 minutes. Use <strong>Provision on demand</strong> to try one person immediately.</li>
</ol>
<div class="note note--warn"><strong>The <code>userName</code> must equal the person’s sign-in name.</strong> It becomes their Care Core email address, and is what Microsoft sign-in matches on the first time they sign in.</div>

<h2>What Care Core supports</h2>
<div class="tablewrap"><table>
<thead><tr><th>Request</th><th>Notes</th></tr></thead>
<tbody>
<tr><td><code>GET /scim/v2/ServiceProviderConfig</code></td><td>Declares: PATCH yes, filter yes (up to 100), bulk no, sort no, change password no.</td></tr>
<tr><td><code>GET /scim/v2/Users</code></td><td>Lists users. Supports the filter <code>userName eq "name@example.com"</code> (the one Entra uses to check whether a user exists). Other filters return a clear error.</td></tr>
<tr><td><code>POST /scim/v2/Users</code></td><td>Creates (joiner or rehire). <code>409</code> if that person is already employed.</td></tr>
<tr><td><code>GET /scim/v2/Users/{id}</code></td><td>One user.</td></tr>
<tr><td><code>PATCH /scim/v2/Users/{id}</code></td><td>Operations <code>replace</code> and <code>add</code>, with or without a <code>path</code>. Accepts Entra’s string values <code>"True"</code> and <code>"False"</code> for <code>active</code>.</td></tr>
<tr><td><code>PUT /scim/v2/Users/{id}</code></td><td>Replaces <code>active</code>, <code>title</code> and the name.</td></tr>
<tr><td><code>DELETE /scim/v2/Users/{id}</code></td><td>Runs the leaver process; returns <code>204</code>.</td></tr>
</tbody></table></div>
<p>Authentication is a <strong>bearer token</strong>: <code>Authorization: Bearer TOKEN</code>. A missing or wrong token gets <code>401</code>. A token belongs to one organisation, so it can only affect that organisation’s people. Tokens can be <strong>revoked</strong> on the same page, and show when they were last used.</p>
<p>Group provisioning (<code>/Groups</code>) is not provided. Roles are set in Care Core.</p>

<h2>Try it by hand</h2>
<pre><code>curl -s https://YOUR-ADDRESS/scim/v2/Users \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/scim+json" \
  -d '{"userName":"ada@example.org","name":{"givenName":"Ada","familyName":"Lovelace"},"title":"Support Worker"}'

# then leave them (Entra sends the string "False")
curl -s -X PATCH https://YOUR-ADDRESS/scim/v2/Users/{id} \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/scim+json" \
  -d '{"schemas":["urn:ietf:params:scim:api:messages:2.0:PatchOp"],
       "Operations":[{"op":"Replace","path":"active","value":"False"}]}'</code></pre>

<h2>Things to know</h2>
<ul>
<li>People are only <em>created</em> or <em>ended</em> by SCIM. Starting a person in Care Core without Entra is still fine; they simply are not in Entra’s scope.</li>
<li>Provisioning creates the person with today as the start date. If the real start date is later, edit it, or start the person in Care Core instead and let Entra take over once they have started.</li>
<li>Each SCIM action appears in the <a href="/docs/admin-activity">activity log</a> as done by the system process “scim”.</li>
</ul>
