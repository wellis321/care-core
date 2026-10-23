<p>Every field on a staff record, with its format and who can change it. <strong>Staff</strong> means the person themselves on <a href="/docs/my-profile">My profile</a>; <strong>Manager</strong> means managers and administrators on the <a href="/docs/staff-record">staff record</a>.</p>

<h2>Personal</h2>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Format and checks</th><th>Staff can change</th><th>Manager</th></tr></thead>
<tbody>
<tr><td>First name, last name</td><td>Required, up to 100 characters</td><td>With approval</td><td>Yes (Edit details)</td></tr>
<tr><td>Known as</td><td>Up to 100 characters</td><td>No</td><td>Yes</td></tr>
<tr><td>Date of birth</td><td>A real date, not in the future, not before 1900</td><td>With approval</td><td>Yes</td></tr>
<tr><td>Email</td><td>An email address; unique for logins</td><td>No</td><td>Yes</td></tr>
<tr><td>Phone</td><td>7 to 25 characters: numbers, spaces, + ( ) -</td><td>Straight away</td><td>Yes</td></tr>
<tr><td>Address lines 1 and 2, town, county, country</td><td>Up to 190 characters</td><td>With approval</td><td>Yes</td></tr>
<tr><td>Postcode</td><td>A UK postcode, tidied to capitals with the space (<code>IV2 3PS</code>)</td><td>With approval</td><td>Yes</td></tr>
<tr><td>Emergency contact name</td><td>Up to 190 characters</td><td>Straight away</td><td>Yes</td></tr>
<tr><td>Emergency contact phone</td><td>As Phone</td><td>Straight away</td><td>Yes</td></tr>
<tr><td>National Insurance number</td><td><code>AB123456C</code>: two letters (not D F I Q U V in the first, not D F I O Q U V in the second, and not BG GB NK KN TN NT ZZ), six digits, a final A to D. Spaces and lower case are tidied.</td><td>With approval</td><td>Yes</td></tr>
<tr><td>Bank sort code</td><td>Six digits, stored as <code>12-34-56</code></td><td>With approval</td><td>Yes</td></tr>
<tr><td>Bank account number</td><td>Eight digits</td><td>With approval</td><td>Yes</td></tr>
<tr><td>Name on the account</td><td>Up to 190 characters</td><td>With approval</td><td>Yes</td></tr>
<tr><td>Photo</td><td>PNG, JPEG, WebP or GIF, up to 3 MB</td><td>With approval</td><td>Yes</td></tr>
<tr><td>Signature</td><td>Drawn, or an image up to 1 MB</td><td>With approval</td><td>Yes</td></tr>
</tbody></table></div>
<p>National Insurance number, sort code, account number and account name are <strong>encrypted at rest</strong>.</p>

<h2>Employment</h2>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Notes</th><th>Changed by</th></tr></thead>
<tbody>
<tr><td>Job title, contract type, hours per week, line manager</td><td>See <a href="/docs/movers">movers</a>. Changes are dated and kept as history.</td><td>Manager (mover process)</td></tr>
<tr><td>Start date, last day, reason for leaving</td><td>Set by the joiner and leaver processes.</td><td>Manager</td></tr>
<tr><td>Employee reference</td><td>Unique within the organisation.</td><td>At joining</td></tr>
</tbody></table></div>

<h2>Employment terms</h2>
<div class="tablewrap"><table>
<thead><tr><th>Section</th><th>Fields</th></tr></thead>
<tbody>
<tr><td>Job terms</td><td>Place of work; bank or casual staff; job description link (http or https) and reference; notes</td></tr>
<tr><td>TUPE</td><td>Transferred under TUPE; transfer date; previous organisation; previous employer reference; contract type; hours per week; salary (£); notes</td></tr>
<tr><td>Working time</td><td>WTD agreed, date, version; opted out of the 48-hour week, date signed, date it expires; notes</td></tr>
<tr><td>Apprenticeship</td><td>Is an apprentice; start; expected end; level; provider</td></tr>
<tr><td>Visa</td><td>Needs a visa; type; reference; issued; expires (required if a visa is needed); sponsor</td></tr>
<tr><td>Leave</td><td>Leave year start and end; annual leave allocation, used, carried over (days); time in lieu earned and used (hours); lying time earned and used (hours)</td></tr>
</tbody></table></div>
<p>Numbers must be zero or more. Dates must be real dates. Terms are changed by managers only.</p>

<h2>Compliance records</h2>
<p>See <a href="/docs/compliance">Registrations, supervision and learning</a> for each field.</p>

<h2>People we support</h2>
<div class="tablewrap"><table>
<thead><tr><th>Field</th><th>Format</th></tr></thead>
<tbody>
<tr><td>Sex (on the person)</td><td>M, F or X</td></tr>
<tr><td>NHS / CHI number (on the person)</td><td>10 digits with a valid check digit; spaces and dashes allowed; one person each</td></tr>
<tr><td>Employment status, GP practice (on the person)</td><td>Free text (100 and 255 characters)</td></tr>
<tr><td>Home address (on the person)</td><td>Address, address line 2, town, area, postcode</td></tr>
<tr><td>Support starts on, ends on</td><td>Real dates; the end is the last day</td></tr>
<tr><td>Reference, local authority reference</td><td>Free text</td></tr>
<tr><td>NHS number</td><td>10 digits with a valid check digit; spaces and dashes allowed</td></tr>
<tr><td>Care need</td><td>Category, description, severity (low, medium, high), review date</td></tr>
<tr><td>Contact</td><td>Type (phone, email, address, next of kin, legal guardian, social worker, GP, other), label, value, primary</td></tr>
</tbody></table></div>
