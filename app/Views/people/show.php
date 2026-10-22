<?php
use App\Core\Csrf;
use App\Core\View;
$e = [View::class, 'e'];
$name = $person['first_name'] . ' ' . $person['last_name'];
$isStaff = $current !== null;
$wasStaff = !$isStaff && $employments;
$base = '/care/' . $person['uuid'];
$types = ['phone' => 'Phone', 'email' => 'Email', 'address' => 'Address', 'next_of_kin' => 'Next of kin', 'legal_guardian' => 'Legal guardian', 'social_worker' => 'Social worker', 'gp' => 'GP', 'other' => 'Other'];
?>
<p class="crumbs"><a href="/people">People</a> › <?= $e($name) ?></p>
<div class="pagehead">
  <div>
    <p class="eyebrow"><?php if ($isStaff && $current['end_date']): ?>Staff · leaves <?= View::date($current['end_date']) ?><?php elseif ($isStaff): ?>Staff<?php elseif ($wasStaff): ?>Former staff<?php endif; ?><?= ($careOn && !empty($episode)) ? ($isStaff || $wasStaff ? ' · ' : '') . 'Person we support' : '' ?></p>
    <h1><?= $e($name) ?></h1>
    <p class="muted"><?= $e($current['job_title'] ?? '') ?><?= $person['preferred_name'] ? ' · known as ' . $e($person['preferred_name']) : '' ?></p>
  </div>
  <div class="actions">
    <?php if ($full && \App\Core\Auth::can('people.edit')): ?><a class="btn btn-secondary" href="/people/<?= $e($person['uuid']) ?>/edit">Edit details</a><?php endif; ?>
    <?php if ($canManage && $isStaff): ?>
      <a class="btn btn-secondary" href="/staff/<?= $e($current['uuid']) ?>/move">Change role or team</a>
      <?php if (!$current['end_date']): ?><a class="btn btn-danger" href="/staff/<?= $e($current['uuid']) ?>/leave">Record leaving</a><?php endif; ?>
    <?php elseif ($canManage && $wasStaff): ?>
      <a class="btn btn-primary" href="/staff/join?person=<?= $e($person['uuid']) ?>">Start new employment</a>
    <?php endif; ?>
  </div>
</div>

<div class="split">
<div>

<?php if ($full): ?>
<section class="section card">
  <h2>Details</h2>
  <dl class="facts">
    <dt>Email</dt><dd><?= $e($person['email'] ?? '') ?: '—' ?></dd>
    <dt>Phone</dt><dd><?= $e($person['phone'] ?? '') ?: '—' ?></dd>
    <dt>Date of birth</dt><dd><?= View::date($person['date_of_birth']) ?></dd>
    <?php if ($person['notes']): ?><dt>Notes</dt><dd><?= nl2br($e($person['notes'])) ?></dd><?php endif; ?>
  </dl>
</section>
<?php endif; ?>

<?php if ($employments): ?>
<section class="section">
  <div class="section__head"><h2>Employment</h2></div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Role</th><th>Started</th><th>Last day</th><?php if ($full): ?><th>Line manager</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($employments as $em): ?>
      <tr>
        <td><?= $e($em['job_title']) ?><br><span class="muted small"><?= $e(ucwords(str_replace('_', ' ', $em['contract_type']))) ?><?= $em['hours_per_week'] ? ' · ' . $e(rtrim(rtrim((string) $em['hours_per_week'], '0'), '.')) . ' h/week' : '' ?><?= $em['employee_ref'] ? ' · ' . $e($em['employee_ref']) : '' ?></span></td>
        <td class="nowrap"><?= View::date($em['start_date']) ?></td>
        <td class="nowrap"><?= $em['end_date'] ? View::date($em['end_date']) . ($em['leaving_reason'] ? '<br><span class="muted small">' . $e($em['leaving_reason']) . '</span>' : '') : '<span class="chip chip--staff">Current</span>' ?></td>
        <?php if ($full): ?><td><?= $em['manager_uuid'] ? '<a href="/people/' . $e($em['manager_uuid']) . '">' . $e($em['manager_first'] . ' ' . $em['manager_last']) . '</a>' : '<span class="muted">—</span>' ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($changes): ?>
  <h3 style="margin-top:1rem">Changes to the current role</h3>
  <div class="card"><ul class="timeline">
    <?php foreach ($changes as $c): ?><li><time><?= View::date($c['effective_date']) ?></time><span><?= $e(ucwords(str_replace('_', ' ', $c['field']))) ?><?= ($c['from_value'] !== null && !in_array($c['field'], ['team', 'line_manager'], true)) ? ': ' . $e($c['from_value']) . ' → ' . $e($c['to_value']) : ' changed' ?></span></li><?php endforeach; ?>
  </ul></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="section">
  <div class="section__head"><h2>Teams</h2></div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Team</th><th>Role</th><th>From</th><th>To</th></tr></thead>
    <tbody>
    <?php foreach ($teams as $t): ?>
      <tr><td><a href="/teams/<?= $e($t['team_uuid']) ?>"><?= $e($t['team_name']) ?></a><?= $t['is_primary'] && $t['valid_to'] === null ? ' <span class="chip chip--staff">Primary</span>' : '' ?></td><td><?= $e($t['role'] ?? '') ?: '<span class="muted">—</span>' ?></td><td class="nowrap"><?= View::date($t['valid_from']) ?></td><td class="nowrap"><?= $t['valid_to'] ? View::date($t['valid_to']) : '<span class="chip chip--staff">Current</span>' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$teams): ?><tr class="empty-row"><td colspan="4">Not in any team.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<?php if ($careOn && ($episode || $keyworkers)): ?>
<section class="section">
  <div class="section__head"><h2>Key workers</h2></div>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Key worker</th><th>From</th><th>To</th><?php if ($canCare): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($keyworkers as $k): ?>
      <tr><td><a href="/people/<?= $e($k['staff_uuid']) ?>"><?= $e($k['first_name'] . ' ' . $k['last_name']) ?></a><?= $k['role_label'] ? '<br><span class="muted small">' . $e($k['role_label']) . '</span>' : '' ?></td><td class="nowrap"><?= View::date($k['valid_from']) ?></td><td class="nowrap"><?= $k['valid_to'] ? View::date($k['valid_to']) : '<span class="chip chip--staff">Current</span>' ?></td>
      <?php if ($canCare): ?><td class="right"><?php if ($k['valid_to'] === null): ?><form method="post" action="<?= $e($base) ?>/keyworkers/end" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="btn btn-secondary" type="submit">End</button></form><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$keyworkers): ?><tr class="empty-row"><td colspan="4"><?= $episode ? 'No key worker yet.' : 'Not a person we support.' ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <?php if ($canCare && $episode && $staffChoices): ?>
  <form class="inlineform" method="post" action="<?= $e($base) ?>/keyworkers" style="margin-top:0.75rem">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="staff_id">Add a key worker</label><select id="staff_id" name="staff_id" required><option value="">Choose…</option><?php foreach ($staffChoices as $s): ?><option value="<?= (int) $s['id'] ?>"><?= $e($s['first_name'] . ' ' . $s['last_name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="role_label">Role</label><input id="role_label" name="role_label" type="text" placeholder="e.g. Primary key worker"></div>
    <button class="btn btn-primary" type="submit">Add</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($careOn && !empty($supervises)): ?>
<section class="section">
  <div class="section__head"><h2>Key worker for</h2></div>
  <div class="tablewrap"><table class="list"><thead><tr><th>Person</th><th>From</th><th>To</th></tr></thead><tbody>
    <?php foreach ($supervises as $k): ?><tr><td><a href="/people/<?= $e($k['supported_uuid']) ?>"><?= $e($k['first_name'] . ' ' . $k['last_name']) ?></a></td><td class="nowrap"><?= View::date($k['valid_from']) ?></td><td class="nowrap"><?= $k['valid_to'] ? View::date($k['valid_to']) : '<span class="chip chip--staff">Current</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>

<?php if ($careOn && ($episodes || ($canCare && !$isStaff))): ?>
<section class="section">
  <div class="section__head"><h2>Support</h2></div>
  <?php if ($episodes): ?>
  <div class="tablewrap"><table class="list"><thead><tr><th>Reference</th><th>Started</th><th>Ended</th><th>NHS number</th></tr></thead><tbody>
    <?php foreach ($episodes as $ep): ?><tr><td><?= $e($ep['reference'] ?? '') ?: '<span class="muted">—</span>' ?></td><td class="nowrap"><?= View::date($ep['start_date']) ?></td><td class="nowrap"><?= $ep['end_date'] ? View::date($ep['end_date']) : '<span class="chip chip--supported">Receiving support</span>' ?></td><td><?= $e($ep['nhs_number'] ?? '') ?: '<span class="muted">—</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($canCare): ?>
    <?php if ($episode): ?>
    <form class="inlineform" method="post" action="<?= $e($base) ?>/end" style="margin-top:0.75rem" onsubmit="return confirm('End support for <?= $e($name) ?>? Their key worker links and team places will end too.')">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="end_date">Support ends on</label><input id="end_date" name="end_date" type="date" value="<?= $e($today) ?>"></div>
      <button class="btn btn-danger" type="submit">End support</button>
    </form>
    <?php else: ?>
    <form class="inlineform" method="post" action="<?= $e($base) ?>/start" style="margin-top:0.75rem">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="start_date">Support starts on</label><input id="start_date" name="start_date" type="date" value="<?= $e($today) ?>"></div>
      <div class="form-group"><label for="reference">Reference</label><input id="reference" name="reference" type="text"></div>
      <div class="form-group"><label for="nhs_number">NHS number</label><input id="nhs_number" name="nhs_number" type="text"></div>
      <button class="btn btn-primary" type="submit">Start support</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($careOn && $episodes): ?>
<section class="section">
  <div class="section__head"><h2>Care needs</h2></div>
  <div class="tablewrap"><table class="list"><thead><tr><th>Need</th><th>Severity</th><th>Review</th><?php if ($canCare): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($needs as $n): ?>
    <tr><td><strong><?= $e($n['category']) ?></strong><br><span class="muted small"><?= $e($n['description']) ?></span></td><td><?= $n['severity'] ? '<span class="chip' . ($n['severity'] === 'high' ? ' chip--bad' : ($n['severity'] === 'medium' ? ' chip--warn' : '')) . '">' . $e(ucfirst($n['severity'])) . '</span>' : '—' ?></td><td class="nowrap"><?= $n['ended_on'] ? 'Closed ' . View::date($n['ended_on']) : View::date($n['review_date']) ?></td>
    <?php if ($canCare): ?><td class="right"><?php if (!$n['ended_on']): ?><form method="post" action="<?= $e($base) ?>/needs/end" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn btn-secondary" type="submit">Close</button></form><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$needs): ?><tr class="empty-row"><td colspan="4">No care needs recorded.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($canCare): ?>
  <form class="inlineform" method="post" action="<?= $e($base) ?>/needs" style="margin-top:0.75rem">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="category">Category</label><input id="category" name="category" type="text" required placeholder="e.g. Mobility"></div>
    <div class="form-group"><label for="description">Description</label><input id="description" name="description" type="text" required></div>
    <div class="form-group"><label for="severity">Severity</label><select id="severity" name="severity"><option value="">—</option><option>low</option><option>medium</option><option>high</option></select></div>
    <div class="form-group"><label for="review_date">Review date</label><input id="review_date" name="review_date" type="date"></div>
    <button class="btn btn-primary" type="submit">Add</button>
  </form>
  <?php endif; ?>
</section>

<section class="section">
  <div class="section__head"><h2>Contacts</h2></div>
  <div class="tablewrap"><table class="list"><thead><tr><th>Type</th><th>Contact</th><?php if ($canCare): ?><th></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($contacts as $c): ?>
    <tr><td><?= $e($types[$c['contact_type']] ?? $c['contact_type']) ?><?= $c['label'] ? '<br><span class="muted small">' . $e($c['label']) . '</span>' : '' ?></td><td><?= $e($c['value']) ?><?= $c['is_primary'] ? ' <span class="chip chip--staff">Primary</span>' : '' ?></td>
    <?php if ($canCare): ?><td class="right"><form method="post" action="<?= $e($base) ?>/contacts/remove" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-secondary" type="submit">Remove</button></form></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$contacts): ?><tr class="empty-row"><td colspan="3">No contacts recorded.</td></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($canCare): ?>
  <form class="inlineform" method="post" action="<?= $e($base) ?>/contacts" style="margin-top:0.75rem">
    <?= Csrf::field() ?>
    <div class="form-group"><label for="contact_type">Type</label><select id="contact_type" name="contact_type"><?php foreach ($types as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="label">Label</label><input id="label" name="label" type="text" placeholder="e.g. Daughter"></div>
    <div class="form-group"><label for="value">Contact</label><input id="value" name="value" type="text" required></div>
    <label class="check"><input type="checkbox" name="is_primary" value="1"> Primary</label>
    <button class="btn btn-primary" type="submit">Add</button>
  </form>
  <?php endif; ?>
</section>
<?php endif; ?>

</div>

<aside>
<?php if ($full): ?>
<?php $open = array_values(array_filter($tasks, fn($t) => $t['status'] === 'open')); $closed = array_values(array_filter($tasks, fn($t) => $t['status'] !== 'open')); ?>
<section class="section card">
  <h2>Tasks</h2>
  <ul class="timeline timeline--narrow" style="margin:0">
  <?php foreach ($open as $t): ?><li><time><?= $t['due_date'] ? 'Due ' . View::date($t['due_date']) : '' ?></time><span><?= $e($t['title']) ?></span></li><?php endforeach; ?>
  <?php if (!$open): ?><li><span class="muted">Nothing open.</span></li><?php endif; ?>
  </ul>
  <?php if ($closed): ?>
  <details style="margin-top:0.75rem"><summary class="muted small" style="cursor:pointer"><?= count($closed) ?> finished</summary>
    <ul class="timeline timeline--narrow" style="margin:0.5rem 0 0">
    <?php foreach ($closed as $t): ?><li><time><?= $t['due_date'] ? View::date($t['due_date']) : '' ?></time><span class="muted"><s><?= $e($t['title']) ?></s> <span class="chip"><?= $e($t['status']) ?></span></span></li><?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</section>
<section class="section card">
  <h2>History</h2>
  <ul class="timeline timeline--narrow" style="margin:0">
  <?php foreach ($activity as $a): ?><li><time><?= View::date($a['created_at']) ?></time><span><?= $e($a['summary']) ?></span></li><?php endforeach; ?>
  <?php if (!$activity): ?><li><span class="muted">Nothing yet.</span></li><?php endif; ?>
  </ul>
</section>
<?php endif; ?>
</aside>
</div>
