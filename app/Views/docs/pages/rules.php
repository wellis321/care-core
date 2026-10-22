<p>Care Core checks these rules <em>before</em> a change is saved, and a change that breaks one is refused with a plain message and saves nothing. They are also the reason the data stays trustworthy. Every rule listed here is covered by an automated test.</p>

<h2>People</h2>
<ul>
<li>First and last name are required (up to 100 characters each). Email must look like an email address. A date of birth cannot be in the future.</li>
<li>A person is created once. Starting someone whose email already exists is refused: start a new employment for the existing person.</li>
</ul>

<h2>Employment</h2>
<ul>
<li>A job title is required (up to 150 characters). Hours per week must be more than 0 and no more than 168.</li>
<li>An employee reference, if given, is unique within the organisation.</li>
<li>A person has <strong>one employment at a time</strong>. A new one must start after the previous one ended.</li>
<li>Nobody can be their own line manager, and a line manager must be chosen from the organisation’s people.</li>
<li>The last day cannot be before the start date. An ended employment cannot be ended again.</li>
<li>A change of role or team must be effective <strong>today or earlier</strong>, never in the future, and the employment must be active on that date.</li>
<li>An unchanged move is refused (“Nothing has changed”).</li>
</ul>

<h2>Teams</h2>
<ul>
<li>A team needs a name. It cannot be its own parent or sit under one of its own descendants.</li>
<li>A team with active sub-teams cannot be closed. A closed team cannot take members.</li>
<li>A person has one active place per team, and one primary team.</li>
<li>A new place cannot start before the person’s previous place in that team ended; a place cannot end before it started.</li>
<li>Staff-only teams refuse people who are only supported.</li>
</ul>

<h2>Care</h2>
<ul>
<li>A person has one support episode at a time. A new one cannot start before the last ended. An episode cannot end before it started.</li>
<li>An NHS number is 10 digits with a correct check digit.</li>
<li>A key worker must be current staff, the person must be receiving support, nobody is their own key worker, and the same pairing cannot be active twice.</li>
<li>Ending support ends the person’s key worker links and team places on the same day.</li>
<li>A need’s severity is low, medium or high.</li>
</ul>

<h2>The leaver process</h2>
<ul>
<li>Ends, on the last day: the employment, every team place (or cancels one not yet started), every key worker link.</li>
<li>Creates a task for every supported person who loses a key worker and for every person who loses a line manager.</li>
<li>Sets every linked login to stop working after the last day.</li>
<li>All of it happens in one transaction: if any part fails, none of it is saved.</li>
</ul>

<h2>Logins</h2>
<ul>
<li>One login per email address, across the whole installation. Passwords are at least 10 characters.</li>
<li>A login works only while active and before its access end date, checked on every request.</li>
<li>You cannot disable your own login.</li>
<li>5 failed sign-ins per email or 20 per network address in 15 minutes pause sign-in.</li>
</ul>

<h2>Self-service</h2>
<ul>
<li>Staff can only change fields marked as self-editable (see the <a href="/docs/field-reference">field reference</a>); anything else is refused.</li>
<li>A request is checked when it is made and again when it is approved.</li>
<li>Nobody can approve their own request. Only the line manager, managers and administrators can approve. Another organisation cannot.</li>
<li>A request already approved or declined cannot be decided again.</li>
<li>Asking again for the same field replaces the earlier request. Asking for what is already there withdraws it.</li>
</ul>

<h2>Compliance records</h2>
<ul>
<li>Required: registration type and expiry date; supervision date and type; appraisal date, type and outcome; learning title, kind and status.</li>
<li>A registration cannot expire before it was issued; learning cannot expire before it was completed; a next-due date cannot be before the date of the supervision or appraisal.</li>
<li>A supervisor or appraiser must be a person in your organisation.</li>
</ul>

<h2>Images</h2>
<ul>
<li>Only PNG, JPEG, WebP or GIF, verified by content (not by file name). Photos up to 3 MB, signatures up to 1 MB; drawn signatures up to 400 KB. Dimensions up to 8000 pixels.</li>
<li>Stored outside the web root under random names; only served after a permission check.</li>
</ul>

<h2>Integrations</h2>
<ul>
<li>Webhook addresses must be https (http only for localhost), without credentials, and not link-local.</li>
<li>SCIM requires a valid bearer token. A token only affects its own organisation.</li>
<li>An ID token from Microsoft must verify completely (signature, issuer, tenant, audience, expiry, nonce) or the sign-in is refused.</li>
</ul>
