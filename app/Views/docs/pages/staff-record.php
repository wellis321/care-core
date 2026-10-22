<p>The <strong>staff record</strong> holds everything about a member of staff beyond the basics on their profile. Managers and administrators open it from the profile with <strong>Staff record</strong>. It works for current staff and for former staff (it then shows the most recent employment).</p>
<p>The page is a set of collapsible sections. Each section has its own <strong>Save</strong> button and saves only its own fields, so saving the TUPE section can never change someone’s leave balance.</p>
<p>Fields that staff can change themselves are explained in <a href="/docs/my-profile">My profile</a>. A full table of every field is in the <a href="/docs/field-reference">field reference</a>.</p>

<h2>Personal details, address, emergency contact, pay and tax</h2>
<ul>
<li><strong>Address:</strong> two address lines, town or city, county, postcode, country. UK postcodes are checked and tidied (<code>iv23ps</code> becomes <code>IV2 3PS</code>).</li>
<li><strong>Emergency contact:</strong> name and phone number.</li>
<li><strong>National Insurance number:</strong> checked against the real format (two letters, six digits, a final letter A to D, and excluded prefixes). Example format: <code>AB123456C</code>.</li>
<li><strong>Bank details:</strong> name on the account, sort code (six digits, shown as <code>12-34-56</code>) and account number (eight digits).</li>
</ul>
<div class="note"><strong>Encrypted.</strong> National Insurance and bank details are encrypted in the database. They are visible here to people who manage staff and, masked (for example <code>••••5678</code>), to the person themselves. The activity log and the outgoing events say <em>which fields</em> changed, never the values.</div>
<h3>Photo and signature</h3>
<p>A manager can set a photo or a signature directly by uploading an image. Staff add their own on <em>My profile</em> (which needs approval). Images must be real PNG, JPEG, WebP or GIF pictures (photos up to 3 MB, signatures up to 1 MB), are stored privately, and are only shown to people who are allowed to see them.</p>

<h2>Job terms</h2>
<p>Place of work, whether they are <strong>bank or casual staff</strong>, a link and reference to the job description held elsewhere, and notes.</p>

<h2>TUPE transfer</h2>
<p>For staff who transferred to you from another employer under TUPE: tick <em>Transferred under TUPE</em>, then record the transfer date, previous organisation, the reference from the previous employer, contract type, hours per week and salary under TUPE, and notes. A <em>TUPE</em> marker shows on the section.</p>

<h2>Working time</h2>
<p>Whether they have <strong>agreed</strong> the Working Time Directive (with date and the version of the agreement), and whether they have <strong>opted out</strong> of the 48-hour week (with the date signed and when the opt-out expires). The opt-out cannot expire before it was signed.</p>

<h2>Apprenticeship</h2>
<p>Whether they are an apprentice, start and expected end dates (the end cannot be before the start), level and training provider.</p>

<h2>Right to work and visa</h2>
<p>Whether they need a visa, the type, reference, issue date, <strong>expiry date</strong> (required if they need a visa), and the sponsor organisation.</p>

<h2>Leave</h2>
<p>The leave year start and end dates (the end cannot be before the start), annual leave allocation, used and carried over (in days), and time in lieu and “lying time” earned and used (in hours). Empty numbers become zero where a figure is needed. These are <em>records</em>: Care Core does not book leave or calculate pro-rata entitlements. See <a href="/docs/limits">What it does not do yet</a>.</p>

<h2>Which employment do these belong to?</h2>
<p>Job terms, TUPE, working time, apprenticeship, visa and leave belong to a single <strong>employment</strong>, because they can differ from one stint to the next. Personal details (address, emergency contact, pay and tax) belong to the <strong>person</strong>. Registrations, supervisions, appraisals and learning belong to the person and carry across employments.</p>

<h2>Everything is recorded</h2>
<p>Every save writes an activity log entry (naming the section or fields, not the values) and an event (<code>person.updated</code> for personal details, <code>employment.changed</code> with the section for terms).</p>
<p>Next: <a href="/docs/compliance">Registrations, supervision and learning</a>.</p>
