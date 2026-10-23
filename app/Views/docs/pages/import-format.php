<p>An import file is one JSON document. Anything that can write JSON (a script, a spreadsheet export, another system’s API) can produce one. Dates are <code>YYYY-MM-DD</code> (a trailing time is ignored). Unknown fields are ignored, so a file can carry extra information safely.</p>

<h2>The shape</h2>
<pre><code>{
  "format": "carecore-import",
  "version": 1,
  "source": "Where this came from (shown in the history)",
  "teams":       [ … ],
  "people":      [ … ],
  "team_places": [ … ],
  "key_workers": [ … ]
}</code></pre>
<p>All four lists are optional. Several files can be imported together: people with the same <code>ref</code> in different files are combined.</p>

<h2>Refs</h2>
<p>Every record should have a <code>ref</code>: a stable identifier <strong>from the source system</strong>, unique within its kind, such as <code>staff:123</code> or <code>team:7</code>. Refs do three jobs:</p>
<ul>
<li><strong>Link records</strong>, even across files: a team place says which <code>team_ref</code> and <code>person_ref</code> it connects.</li>
<li><strong>Make re-runs safe</strong>: a record whose ref was imported before is skipped.</li>
<li><strong>Report problems</strong>: warnings and errors name the ref, so you can find the source record.</li>
</ul>
<p>A ref without a record behind it is simply not found: the link is dropped with a warning. A person can have extra <code>"aliases": ["people:5"]</code> if the same human is known by more than one ref.</p>

<h2>teams[]</h2>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Notes</th></tr></thead>
<tbody>
<tr><td><code>ref</code></td><td>Required for linking.</td></tr>
<tr><td><code>name</code></td><td>Required.</td></tr>
<tr><td><code>parent_ref</code></td><td>The team above it. Parents may appear after children.</td></tr>
<tr><td><code>kind</code></td><td>For example <code>care</code>, <code>hr</code>. Default <code>care</code>.</td></tr>
<tr><td><code>staff_only</code></td><td>true or false.</td></tr>
<tr><td><code>active</code></td><td><code>false</code> closes the team after its history is imported.</td></tr>
</tbody></table></div>
<p>A team with the same name and parent that already exists is <em>matched</em>, not duplicated.</p>

<h2>people[]</h2>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Notes</th></tr></thead>
<tbody>
<tr><td><code>ref</code>, <code>aliases</code></td><td>See above.</td></tr>
<tr><td><code>first_name</code>, <code>last_name</code></td><td><strong>Required.</strong> A person without both is an error.</td></tr>
<tr><td><code>preferred_name</code>, <code>date_of_birth</code>, <code>email</code>, <code>phone</code>, <code>notes</code>, <code>sex</code>, <code>nhs_number</code>, <code>employment_status</code>, <code>gp_practice</code>, <code>address_line1</code>, <code>address_line2</code>, <code>town</code>, <code>area</code>, <code>postcode</code></td><td>Optional. The structured details only ever fill blanks on someone already in Care Core. An invalid email, phone or date is dropped with a warning.</td></tr>
<tr><td><code>profile</code></td><td>An object: <code>address_line1</code>, <code>address_line2</code>, <code>address_city</code>, <code>address_county</code>, <code>address_postcode</code>, <code>address_country</code>, <code>emergency_contact_name</code>, <code>emergency_contact_phone</code>, <code>ni_number</code>, <code>bank_sort_code</code>, <code>bank_account_number</code>, <code>bank_account_name</code>. Each value is checked; a bad one is dropped with a warning. See the <a href="/docs/field-reference">field reference</a>.</td></tr>
<tr><td><code>photo_file</code>, <code>signature_file</code>, <code>signature_method</code></td><td>A path inside the folder given as <code>--files-from</code> (command line only). <code>signature_method</code> is <code>draw</code> or <code>upload</code>.</td></tr>
<tr><td><code>employments[]</code></td><td>See below.</td></tr>
<tr><td><code>support[]</code></td><td>Support episodes, below.</td></tr>
<tr><td><code>needs[]</code>, <code>contacts[]</code></td><td>Below.</td></tr>
<tr><td><code>registrations[]</code>, <code>supervisions[]</code>, <code>appraisals[]</code>, <code>learning[]</code></td><td>Below.</td></tr>
</tbody></table></div>

<h3>employments[]</h3>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Notes</th></tr></thead>
<tbody>
<tr><td><code>ref</code></td><td>Recommended.</td></tr>
<tr><td><code>job_title</code></td><td>Missing → “Employee”, with a warning.</td></tr>
<tr><td><code>start_date</code></td><td><strong>Required.</strong> Without a usable one the employment is skipped.</td></tr>
<tr><td><code>end_date</code></td><td>The last day. Before the start → dropped with a warning.</td></tr>
<tr><td><code>contract_type</code>, <code>hours_per_week</code></td><td>Hours must be above 0 and no more than 168, otherwise dropped with a warning.</td></tr>
<tr><td><code>employee_ref</code></td><td>Must be unique in the organisation; a duplicate is dropped with a warning.</td></tr>
<tr><td><code>leaving_reason</code></td><td>Free text.</td></tr>
<tr><td><code>line_manager_ref</code></td><td>The manager’s person ref. Resolved after everyone is in, so order does not matter.</td></tr>
<tr><td><code>details</code></td><td>An object with sections <code>terms</code>, <code>tupe</code>, <code>wtd</code>, <code>apprentice</code>, <code>visa</code>, <code>leave</code>, each with the fields listed in the <a href="/docs/field-reference">field reference</a>. A section with a bad value is left out entirely, with a warning.</td></tr>
<tr><td><code>changes[]</code></td><td>History of changes: <code>{"effective_date", "field", "from", "to"}</code> where field is <code>job_title</code>, <code>contract_type</code>, <code>hours_per_week</code>, <code>line_manager</code> or <code>team</code>.</td></tr>
</tbody></table></div>
<p>An employment that overlaps one the person already has is skipped with a warning.</p>

<h3>support[]</h3>
<p><code>ref</code>, <code>start_date</code> (required), <code>end_date</code>, <code>reference</code>, <code>nhs_number</code> (an invalid one is dropped), <code>local_authority_ref</code>. Episodes must not overlap.</p>

<h3>needs[], contacts[]</h3>
<ul>
<li>Need: <code>category</code>, <code>description</code> (both required), <code>severity</code> (<code>low</code>, <code>medium</code>, <code>high</code>), <code>review_date</code>, <code>ended_on</code>.</li>
<li>Contact: <code>type</code> (<code>phone</code>, <code>email</code>, <code>address</code>, <code>next_of_kin</code>, <code>legal_guardian</code>, <code>social_worker</code>, <code>gp</code>, <code>other</code>; anything else becomes <code>other</code>), <code>value</code>, <code>label</code>, <code>primary</code>.</li>
</ul>

<h3>registrations[], supervisions[], appraisals[], learning[]</h3>
<p>The same fields as in <a href="/docs/compliance">Registrations, supervision and learning</a>. For supervisions use <code>supervisor_ref</code> (a person ref) instead of a person; for appraisals, <code>appraiser_ref</code>. Learning <code>record_type</code> values outside the list become <code>other</code>. A record that fails the checks is skipped with a warning.</p>

<h2>team_places[]</h2>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Notes</th></tr></thead>
<tbody>
<tr><td><code>ref</code>, <code>team_ref</code>, <code>person_ref</code></td><td>Both refs must be in the files or already imported.</td></tr>
<tr><td><code>role</code>, <code>primary</code></td><td><code>primary</code> is applied only to a place that has not ended.</td></tr>
<tr><td><code>start_date</code></td><td>Required.</td></tr>
<tr><td><code>end_date</code></td><td>Optional. If the person left (or support ended) and this is empty, it is set to their last day.</td></tr>
</tbody></table></div>
<p>The usual team rules apply: no two overlapping stints in a team, staff-only teams refuse people we support. A place that breaks one is skipped with the reason.</p>

<h2>key_workers[]</h2>
<p><code>ref</code>, <code>supported_ref</code>, <code>staff_ref</code>, <code>role_label</code>, <code>start_date</code> (default today), <code>end_date</code>. The key worker must have been current staff, and the person supported must have been receiving support, on the start date. A link left open for someone who has since left is closed on their last day.</p>

<h2>A complete small example</h2>
<pre><code>{
  "format": "carecore-import", "version": 1, "source": "Spreadsheet, October 2026",
  "teams": [
    { "ref": "t1", "name": "Loch View Care Home", "kind": "care" },
    { "ref": "t2", "name": "Day Team", "parent_ref": "t1" }
  ],
  "people": [
    { "ref": "s1", "first_name": "Fiona", "last_name": "Maclean", "email": "fiona@example.org",
      "profile": { "address_city": "Inverness", "address_postcode": "IV2 4SE" },
      "employments": [ { "ref": "s1e", "job_title": "Registered Manager", "start_date": "2018-04-02", "hours_per_week": 37.5 } ],
      "registrations": [ { "registration_type": "SSSC registered manager", "expiry_date": "2027-01-01" } ] },
    { "ref": "s2", "first_name": "Callum", "last_name": "Reid",
      "employments": [ { "ref": "s2e", "job_title": "Support Worker", "start_date": "2022-09-13", "line_manager_ref": "s1" } ] },
    { "ref": "c1", "first_name": "Aileen", "last_name": "Duncan", "date_of_birth": "1952-12-30",
      "support": [ { "ref": "c1s", "start_date": "2019-03-04", "nhs_number": "943 476 5919" } ],
      "needs": [ { "category": "Falls risk", "description": "Review every six months", "severity": "high" } ] }
  ],
  "team_places": [
    { "ref": "p1", "team_ref": "t2", "person_ref": "s2", "role": "Care worker", "primary": true, "start_date": "2022-09-13" }
  ],
  "key_workers": [
    { "ref": "k1", "supported_ref": "c1", "staff_ref": "s2", "start_date": "2022-10-01" }
  ]
}</code></pre>
<p>Save it as <code>example.json</code>, then check it: <code>php scripts/import.php --org=your-org example.json</code>.</p>

<h2>From a spreadsheet</h2>
<p>Export each sheet to CSV and write a short script (in any language) that builds this JSON: one object per row, with the row’s id as the <code>ref</code>. Run it, check the report, fix the spreadsheet, and repeat until the warnings are ones you accept. Because re-running is safe, you can go round this loop as often as you like before importing for real.</p>
<p>Next: <a href="/docs/import-migrating">Moving from the older Staff, People and Team services</a>.</p>
