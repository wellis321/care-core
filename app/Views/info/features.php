<link rel="stylesheet" href="/assets/story.css">
<div class="story"><div class="story-wrap">
<section class="story-hero">
 <div>
  <span class="story-kicker">Features</span>
  <h1>Everything Care Core does</h1>
  <p class="lead">A complete list, by area. Each area links to its page in the documentation.</p>
  <p class="story-note">The picture is a drawn illustration with invented data.</p>
 </div>
 <div class="scene" aria-hidden="true"><div class="scene-bar"><span class="dot"></span>Start a new member of staff<span class="tag">One step</span></div><div class="scene-body">
   <div class="srow"><div><div class="t">Job, hours and line manager</div><div class="s">Support worker, 37.5 hours</div></div><span class="st">Set</span></div>
   <div class="srow"><div><div class="t">Team</div><div class="s">Harbour team</div></div><span class="st">Placed</span></div>
   <div class="srow"><div><div class="t">Onboarding checklist</div><div class="s">Tasks created for the manager</div></div><span class="st thistle">Created</span></div>
   <div class="srow"><div><div class="t">Optional login</div><div class="s">Invitation sent</div></div><span class="st gold">Invited</span></div>
 </div><div class="scene-foot">Illustration with invented data</div></div>
</section>
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
  'Recruitment' => ['recruitment', [
    'Job roles as a reference: what the job is, who it reports to, where it normally sits, and the checks needed before someone can start',
    'Vacancies for one or many posts, with a line manager and team, opened and closed when you choose',
    'Candidates move from applied through interview to an offer, with notes, dates and a visible history',
    'Nobody can be hired until the offer is accepted and every required check is done',
    'Hiring starts them through the ordinary joiner process: their record, team, onboarding and the events your other systems listen to',
    'Unsuccessful candidates never become people, and their details can be removed',
    'A public careers page and apply form, with no account and no tracking, protected against robots',
    'Interviews booked with a private link the candidate uses to say whether they can come',
  ]],
  'Leave and documents' => ['leave', [
    'Leave requests decided by a manager (never your own), sickness with a return-to-work prompt, and annual leave allowances',
    'A team calendar of who is away, in letters as well as colour',
    'Private staff documents with expiry dates, optional sharing with the person, and a review list',
    'A retention review list and a one-file subject access export',
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
  'Moving in' => ['import', [
    'Exporters for the older Staff, People and Team services (read only) and a documented import format for anything else',
    'A dry run that does all the work and rolls it back, so the report is exactly what an import would do',
    'The same rules as typed data; bad values are dropped with a warning, not allowed to break a record',
    'Matches people who are already there, fills blanks, never overwrites; safe to run again',
    'History stays history, and nothing outlives the people in it; no webhook flood, no onboarding tasks for long-serving staff',
  ]],
  'The API' => ['api', [
    'A versioned REST API (/api/v1) over HTTPS with 60+ endpoints covering people, staff, compliance, teams, care, tasks, events, webhooks and audit',
    'Scoped API keys: least privilege, address allow-lists, expiry, per-key rate limits, instant revocation, shown once',
    'The same rules, activity log and events as the web interface: a leaver is still one all-or-nothing transaction',
    'Idempotent POSTs, cursor pagination, updated_since syncing, a pollable event feed, and RFC 9457 error documents',
    'An OpenAPI 3.1 document generated from the code, plus recipes and client examples in PHP, Node.js and Python',
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
?>
<ul class="feat-index" aria-label="Jump to an area">
<?php $n = 0; foreach ($areas as $title => [$doc, $items]): $n++; ?><li><a href="#a<?= $n ?>"><?= htmlspecialchars($title) ?></a></li><?php endforeach; ?>
</ul>
<div class="feat-grid">
<?php $n = 0; foreach ($areas as $title => [$doc, $items]): $n++;
  $cls = in_array($title, ['Trust and control', 'Joiners, movers and leavers'], true) ? ' dark' : ''; if (in_array($title, ['The API', 'Moving in'], true)) $cls .= ' wide'; ?>
<section class="feat-card<?= $cls ?>" id="a<?= $n ?>"><span class="num"><?= sprintf('%02d', $n) ?></span><h2><?= htmlspecialchars($title) ?></h2>
  <ul><?php foreach ($items as $i): ?><li><?= htmlspecialchars($i) ?></li><?php endforeach; ?></ul>
  <a class="more" href="/docs/<?= htmlspecialchars($doc) ?>">Read more &rsaquo;</a></section>
<?php endforeach; ?>
</div>
<p class="actions"><a class="btn btn-primary" href="/docs">Browse the documentation</a> <a class="btn btn-secondary" href="/docs/limits">What it does not do yet</a></p>
</div></div>
