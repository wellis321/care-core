<?php use App\Core\Csrf; use App\Core\View; use App\Modules\Referrals\ReferralRepo; $e = [View::class, 'e'];
$open = in_array($r['status'], ['received', 'assessing'], true);
$old = ['person_name' => $r['person_name'], 'date_of_birth' => $r['date_of_birth'], 'nhs_number' => $r['nhs_number'], 'source' => $r['source'], 'referrer' => $r['referrer'], 'received_on' => $r['received_on'], 'wanted_from' => $r['wanted_from'], 'reason' => $r['reason'],
        'needs_summary' => $r['needs_summary'], 'what_matters' => $r['what_matters'], 'risks' => $r['risks'], 'plan_types' => $r['plan_types'], 'skills' => implode("\n", $skills)]; ?>
<p class="crumbs"><a href="/referrals">Referrals</a> › <?= $e($r['reference']) ?></p>
<div class="pagehead"><div><p class="eyebrow"><?= $e($r['reference']) ?> · <?= $e(ReferralRepo::STATUS[$r['status']]) ?></p><h1><?= $e($r['person_name']) ?></h1>
  <p class="muted">From <?= $e(strtolower(ReferralRepo::SOURCES[$r['source']] ?? $r['source'])) ?><?= $r['referrer'] ? ' (' . $e($r['referrer']) . ')' : '' ?> · received <?= View::date($r['received_on']) ?><?= $r['wanted_from'] ? ' · wanted from ' . View::date($r['wanted_from']) : '' ?></p></div></div>
<div class="split">
<div>
  <section class="card section">
    <h2>The referral</h2>
    <dl class="facts">
      <?php if ($r['date_of_birth']): ?><dt>Date of birth</dt><dd><?= View::date($r['date_of_birth']) ?></dd><?php endif; ?>
      <dt>Why</dt><dd><?= $e($r['reason']) ?></dd>
      <?php if ($r['needs_summary']): ?><dt>What they need</dt><dd><?= nl2br($e($r['needs_summary'])) ?></dd><?php endif; ?>
      <?php if ($r['what_matters']): ?><dt>What matters to them</dt><dd><?= nl2br($e($r['what_matters'])) ?></dd><?php endif; ?>
      <?php if ($r['risks']): ?><dt>Known risks</dt><dd><?= nl2br($e($r['risks'])) ?></dd><?php endif; ?>
      <?php if ($r['plan_types']): ?><dt>Plans it calls for</dt><dd><?= $e(implode(', ', array_map(fn($k) => ReferralRepo::PLAN_TYPES[$k] ?? $k, ReferralRepo::planTypes($r['plan_types'])))) ?></dd><?php endif; ?>
      <?php if ($skills): ?><dt>Specialist skills</dt><dd><?= $e(implode(', ', $skills)) ?></dd><?php endif; ?>
      <?php if ($r['decided_at']): ?><dt>Decision</dt><dd><?= $e(ReferralRepo::STATUS[$r['status']]) ?> on <?= View::date((string) $r['decided_at']) ?><?= $r['decision_reason'] ? ': ' . $e($r['decision_reason']) : '' ?></dd><?php endif; ?>
      <?php if ($person): ?><dt>Person</dt><dd><a href="/people/<?= $e($person['uuid']) ?>"><?= $e($person['first_name'] . ' ' . $person['last_name']) ?></a></dd><?php endif; ?>
    </dl>
  </section>
  <?php if ($open): ?>
  <section class="card section">
    <h2>Change the referral</h2>
    <form class="form" method="post" action="/referrals/<?= $e($r['uuid']) ?>"><?= Csrf::field() ?><?php require __DIR__ . '/_form_fields.php'; ?><button class="btn btn-secondary" type="submit">Save changes</button></form>
  </section>
  <?php endif; ?>
</div>
<aside>
  <?php if ($r['status'] !== 'declined' && $r['status'] !== 'withdrawn'): ?>
  <section class="card section">
    <h2>Decide</h2>
    <form class="form" method="post" action="/referrals/<?= $e($r['uuid']) ?>/status">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="status">Mark as</label><select id="status" name="status">
        <?php if ($r['status'] === 'received'): ?><option value="assessing">Being assessed</option><?php endif; ?>
        <?php if ($open): ?><option value="accepted">Accepted</option><option value="declined">Declined</option><?php endif; ?>
        <option value="withdrawn">Withdrawn</option></select></div>
      <div class="form-group"><label for="reason">Reason (needed to decline or withdraw)</label><input id="reason" name="reason" type="text" maxlength="400"></div>
      <button class="btn btn-primary" type="submit">Save</button>
    </form>
  </section>
  <?php endif; ?>
  <?php if ($r['status'] === 'accepted' && !$r['person_id']): ?>
  <section class="card section">
    <h2>Link to the person</h2>
    <p class="muted small">When they arrive, link the referral so their plan and records can start from it.</p>
    <form class="form" method="post" action="/referrals/<?= $e($r['uuid']) ?>/link">
      <?= Csrf::field() ?>
      <div class="form-group"><label for="person">Person</label><select id="person" name="person"><option value="new">Start a new person record from the name above</option><?php foreach ($people as $p): ?><option value="<?= $e($p['uuid']) ?>"><?= $e($p['last_name'] . ', ' . $p['first_name']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary" type="submit">Link</button>
    </form>
  </section>
  <?php endif; ?>
</aside>
</div>
