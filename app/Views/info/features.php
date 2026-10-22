<div class="hero">
  <p class="eyebrow">Features</p>
  <h1>Everything Care Core does</h1>
  <p class="lede">A complete list, by area. Each item links to its page in the documentation.</p>
</div>
<?php
$areas = [
  'Joiners, movers and leavers' => ['joiners', [
    'Start someone with their job, hours, line manager, team and an optional login, in one step',
    'Rehire returners as a new employment for the same person: no duplicates',
    'Change role, contract, hours, line manager or team with an effective date and a visible history',
    'Preview exactly what leaving will do before you record it',
    'Leavers end their employment, team places, key worker links and login together, on the right day',
    'Follow-up tasks created for each person who needs a new key worker or line manager',
    'Editable checklists for new starters, role changes and leavers',
  ]],
  'The staff record' => ['staff-record', [
    'Address, emergency contact, National Insurance and bank details (encrypted), photo and signature',
    'Job terms, bank or casual status, job description links',
    'TUPE transfers with previous employer, terms and notes',
    'Working Time Directive agreement and 48-hour opt-out with expiry',
    'Apprenticeship details, visa and right-to-work with expiry, leave year and balances',
    'Checks for UK postcodes, National Insurance numbers, sort codes, account numbers and phone numbers',
  ]],
  'Compliance' => ['compliance', [
    'Professional registrations (for example SSSC) with expiry',
    'Supervision records with supervisor, type, outcome and next due date',
    'Appraisals with outcomes and next due date',
    'Learning, qualifications and mandatory training with expiry',
    'A list of everything overdue or due in the next 60 days, for current staff only',
  ]],
  'Staff self-service' => ['my-profile', [
    'Staff keep their own phone and emergency contact up to date instantly',
    'Name, date of birth, address, National Insurance, bank, photo and signature go to their line manager for approval',
    'Draw a signature with a mouse, finger or pen, or upload a picture',
    'Approvers see old and new values side by side, and the new picture itself',
    'Nobody can approve their own request; requests can be withdrawn or replaced',
  ]],
  'Teams' => ['teams', [
    'Teams and sub-teams to any depth, with loops refused',
    'Membership as dated stints: leaving and rejoining never overwrites history',
    'Primary team for each member of staff, and a role within each team',
    'Staff-only teams that refuse people we support',
    'Closing a team ends its places',
  ]],
  'People we support' => ['care', [
    'Support episodes with start and end, references and a checked NHS number',
    'Care needs with category, severity and review dates; contacts of every type',
    'Key workers who must be current staff for someone currently supported',
    'Ending support ends the key worker links and team places with it',
  ]],
  'Sign-in and access' => ['signing-in', [
    'Sign in with Microsoft (OpenID Connect with PKCE, full token verification, one tenant)',
    'Invitations, passwords, a way to change them and to recover a forgotten one',
    'Lock-out after repeated failures; sessions re-checked on every request',
    'Administrator, manager and staff roles; line managers can review their reports',
    'A leaver’s access ends on the day after their last day with no job to run',
  ]],
  'Integrations' => ['webhooks', [
    'Provision from Microsoft Entra with SCIM 2.0: creates joiners, ends leavers, handles returners',
    'Signed webhooks with filters and retries; events carry identifiers and dates only',
    'A documented event catalogue, with receiver examples in PHP, Node.js and Python',
  ]],
  'Trust and control' => ['security', [
    'An append-only activity log written in the same transaction as every change',
    'Consistency checks that find and fix a leaver still in a team, a login, or a key worker link',
    'Modules that can be switched off, separate organisations that cannot see each other',
    'National Insurance and bank details encrypted at rest; activity and events never contain the values',
  ]],
  'Running it' => ['install', [
    'Plain PHP and MySQL on ordinary hosting: no framework, no build step',
    'Upgrades that apply once, in order, without dropping data',
    'Tested: domain tests, end-to-end checks through the interface, and a demo that resets nightly',
  ]],
];
foreach ($areas as $title => [$doc, $items]): ?>
<section class="section"><h2><?= htmlspecialchars($title) ?></h2>
  <ul style="max-width:52rem;line-height:1.7"><?php foreach ($items as $i): ?><li><?= htmlspecialchars($i) ?></li><?php endforeach; ?></ul>
  <p><a href="/docs/<?= htmlspecialchars($doc) ?>">Read more ›</a></p></section>
<?php endforeach; ?>
<p class="actions"><a class="btn btn-primary" href="/docs">Browse the documentation</a> <a class="btn btn-secondary" href="/docs/limits">What it does not do yet</a></p>
