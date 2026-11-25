<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e'];
$fmt = static fn($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
$letter = ['annual' => 'A', 'sickness' => 'S', 'other_paid' => 'O', 'unpaid' => 'U'];
$months = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
$first = $month['first']; ?>
<div class="pagehead"><div><p class="eyebrow">Staff</p><h1>Team leave</h1><p class="muted">Decide requests, see who is away, and record leave. <a href="/leave">Your own leave</a> · <a href="/documents">Documents to review</a></p></div></div>

<?php if (!empty($swapsToDecide)): ?>
<section class="card section"><h2>Leave swaps waiting for a decision <span class="muted small">(<?= count($swapsToDecide) ?>)</span></h2>
<p class="muted small">Both people agreed. Approving makes the two leave records change dates; each keeps the same number of days.</p>
<?php foreach ($swapsToDecide as $w): $own = in_array($myPid, [(int) $w['from_person_id'], (int) $w['to_person_id']], true); ?>
<div class="section"><p><strong><?= $e($w['f_first'] . ' ' . $w['f_last']) ?></strong> (<?= View::date($w['f_start']) ?> to <?= View::date($w['f_end']) ?>) and <strong><?= $e($w['t_first'] . ' ' . $w['t_last']) ?></strong> (<?= View::date($w['t_start']) ?> to <?= View::date($w['t_end']) ?>) swap dates · <?= $e($fmt($w['f_days'])) ?> days each</p>
<?php if ($w['note']): ?><p class="muted small">“<?= $e($w['note']) ?>”</p><?php endif; ?>
<?php if ($own): ?><p class="muted small">This involves you. Another manager has to decide it.</p><?php else: ?>
<form class="form" method="post" action="/leave/swap/<?= $e($w['uuid']) ?>/decide"><?= Csrf::field() ?><div class="form-group"><label for="sn_<?= $e($w['uuid']) ?>">Note (needed if refusing)</label><input id="sn_<?= $e($w['uuid']) ?>" name="note" type="text" maxlength="500"></div>
<button class="btn btn-primary" type="submit" name="decision" value="approve">Approve</button> <button class="btn btn-secondary" type="submit" name="decision" value="refuse">Refuse</button></form><?php endif; ?></div>
<?php endforeach; ?></section>
<?php endif; ?>
<section class="card section" id="requests">
  <h2>Waiting for a decision <span class="muted small">(<?= count($pending) ?>)</span></h2>
  <?php if (!$pending): ?><p class="muted">Nothing waiting.</p><?php endif; ?>
  <?php foreach ($pending as $a): $own = $myPid === (int) $a['person_id']; ?>
  <div class="section">
    <p><strong><a href="/people/<?= $e($a['person_uuid']) ?>"><?= $e($a['first_name'] . ' ' . $a['last_name']) ?></a></strong> · <?= $e($kinds[$a['kind']]) ?> · <?= View::date($a['starts_on']) ?><?= $a['ends_on'] !== $a['starts_on'] ? ' to ' . View::date($a['ends_on']) : '' ?> · <?= $e($fmt($a['days'])) ?> day<?= (float) $a['days'] === 1.0 ? '' : 's' ?></p>
    <?php if ($a['reason']): ?><p class="muted small">“<?= $e($a['reason']) ?>”</p><?php endif; ?>
    <?php if ($own): ?><p class="muted small">This is your own request. Another manager has to decide it.</p>
    <?php else: ?>
    <form class="form" method="post" action="/leave/<?= $e($a['uuid']) ?>/decide"><?= Csrf::field() ?>
      <div class="formgrid">
        <div class="form-group"><label for="note_<?= $e($a['uuid']) ?>">Note to them (needed if declining)</label><input id="note_<?= $e($a['uuid']) ?>" name="note" type="text" maxlength="500"></div>
        <div class="form-group"><label for="days_<?= $e($a['uuid']) ?>">Days (change if a bank holiday falls inside)</label><input id="days_<?= $e($a['uuid']) ?>" name="days" type="number" step="0.5" min="0.5" placeholder="<?= $e($fmt($a['days'])) ?>"></div>
      </div>
      <button class="btn btn-primary" type="submit" name="decision" value="approve">Approve</button>
      <button class="btn btn-secondary" type="submit" name="decision" value="decline">Decline</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>

<div class="split">
<div>
<section class="card section" id="calendar">
  <h2>Who is away</h2>
  <form method="get" action="/leave/team#calendar" class="form"><div class="formgrid">
    <div class="form-group"><label for="team">Team</label><select id="team" name="team"><option value="">Everyone</option><?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>"<?= (int) $teamId === (int) $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label for="month">Month</label><input id="month" name="month" type="month" value="<?= $e(substr($first, 0, 7)) ?>"></div></div>
    <button class="btn btn-secondary" type="submit">Show</button>
    <a href="/leave/team?month=<?= $e($prev) ?><?= $teamId ? '&amp;team=' . (int) $teamId : '' ?>#calendar">Earlier</a> · <a href="/leave/team?month=<?= $e($next) ?><?= $teamId ? '&amp;team=' . (int) $teamId : '' ?>#calendar">Later</a>
  </form>
  <h3><?= $e($months[(int) substr($first, 5, 2)] . ' ' . substr($first, 0, 4)) ?></h3>
  <div class="tablewrap"><table class="cal">
    <thead><tr><th scope="col">Person</th><?php for ($d = 1; $d <= $month['days']; $d++): $date = substr($first, 0, 8) . sprintf('%02d', $d); $we = (int) date('N', strtotime($date)) >= 6; ?><th scope="col" class="<?= $we ? 'we' : '' ?><?= $date === $today ? ' today' : '' ?>"><?= $d ?></th><?php endfor; ?></tr></thead>
    <tbody>
    <?php foreach ($month['people'] as $pe): ?>
      <tr><th scope="row"><a href="/people/<?= $e($pe['uuid']) ?>"><?= $e($pe['first_name'] . ' ' . $pe['last_name']) ?></a></th>
      <?php for ($d = 1; $d <= $month['days']; $d++): $date = substr($first, 0, 8) . sprintf('%02d', $d); $we = (int) date('N', strtotime($date)) >= 6; $cell = null;
        foreach ($month['absences'][(int) $pe['id']] ?? [] as $a) { if ($a['starts_on'] <= $date && ($a['ends_on'] === null || $a['ends_on'] >= $date) && ($a['ends_on'] !== null || $date <= $today)) { $cell = $a; break; } } ?>
        <?php if ($cell && !$we): $ask = $cell['status'] === 'requested'; ?><td class="<?= $ask ? 'ask' : 'off' ?><?= $cell['kind'] === 'sickness' ? ' sick' : '' ?><?= $date === $today ? ' today' : '' ?>" title="<?= $e($kinds[$cell['kind']] . ($ask ? ' (waiting for a decision)' : '')) ?>"><?= $letter[$cell['kind']] ?><?= $ask ? '?' : '' ?></td>
        <?php else: ?><td class="<?= $we ? 'we' : '' ?><?= $date === $today ? ' today' : '' ?>"></td><?php endif; ?>
      <?php endfor; ?></tr>
    <?php endforeach; ?>
    <?php if (!$month['people']): ?><tr><td colspan="<?= $month['days'] + 1 ?>" class="muted">No staff to show.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <p class="small muted">A annual leave · S sickness · O other paid leave · U unpaid leave · ? waiting for a decision. Weekends are shaded.</p>
</section>
</div>
<aside>
  <section class="card section">
    <h2>Away today</h2>
    <?php if ($off): ?><ul class="plainlist"><?php foreach ($off as $a): ?><li><a href="/people/<?= $e($a['person_uuid']) ?>"><?= $e($a['first_name'] . ' ' . $a['last_name']) ?></a> <span class="muted small">· <?= $e($kinds[$a['kind']]) ?></span></li><?php endforeach; ?></ul><?php else: ?><p class="muted">Nobody.</p><?php endif; ?>
  </section>
  <?php if ($sickOpen): ?>
  <section class="card section"><h2>Still off sick</h2>
    <?php foreach ($sickOpen as $a): ?><form method="post" action="/leave/<?= $e($a['uuid']) ?>/back" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="back" value="/leave/team"><span><a href="/people/<?= $e($a['person_uuid']) ?>"><?= $e($a['first_name'] . ' ' . $a['last_name']) ?></a> since <?= View::date($a['starts_on']) ?></span> <label class="sr" for="e_<?= $e($a['uuid']) ?>">Last day off</label><input id="e_<?= $e($a['uuid']) ?>" type="date" name="ends_on" max="<?= $e($today) ?>" value="<?= $e($today) ?>"><button class="btn btn-secondary" type="submit">Back at work</button></form><?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php if ($awaitingRtw): ?>
  <section class="card section"><h2>Return-to-work conversations</h2>
    <?php foreach ($awaitingRtw as $a): ?><form method="post" action="/leave/<?= $e($a['uuid']) ?>/rtw" class="row-actions"><?= Csrf::field() ?><span><a href="/people/<?= $e($a['person_uuid']) ?>"><?= $e($a['first_name'] . ' ' . $a['last_name']) ?></a>, back <?= View::date($a['ends_on']) ?></span> <button class="btn btn-secondary" type="submit">Held today</button></form><?php endforeach; ?>
  </section>
  <?php endif; ?>
  <section class="card section">
    <h2>Record leave for someone</h2>
    <p class="muted small">For leave already agreed, or sickness reported to you. It is recorded as approved.</p>
    <form class="form" method="post" action="/leave/record"><?= Csrf::field() ?>
      <div class="form-group"><label for="r_person">Person</label><select id="r_person" name="person" required><option value="">Choose</option><?php foreach ($month['people'] as $pe): ?><option value="<?= $e($pe['uuid']) ?>"><?= $e($pe['last_name'] . ', ' . $pe['first_name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="r_kind">What</label><select id="r_kind" name="kind"><?php foreach ($kinds as $k => $lbl): ?><option value="<?= $e($k) ?>"><?= $e($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="r_s">First day</label><input id="r_s" name="starts_on" type="date" required></div>
      <div class="form-group"><label class="check"><input type="checkbox" name="start_half" value="1"> Half day</label></div>
      <div class="form-group"><label for="r_e">Last day (empty for sickness not yet ended)</label><input id="r_e" name="ends_on" type="date"></div>
      <div class="form-group"><label class="check"><input type="checkbox" name="end_half" value="1"> Last day is a half day</label></div>
      <div class="form-group"><label for="r_n">Note (optional)</label><input id="r_n" name="reason" type="text" maxlength="500"></div>
      <button class="btn btn-secondary" type="submit">Record</button>
    </form>
  </section>
</aside>
</div>

<section class="card section" id="allowances">
  <h2>Allowances and sickness</h2>
  <form method="post" action="/leave/settings" class="form"><?= Csrf::field() ?>
    <div class="form-group"><label for="ym">The leave year starts in</label><select id="ym" name="month"><?php foreach ($months as $n => $m): ?><option value="<?= $n ?>"<?= $yearMonth === $n ? ' selected' : '' ?>><?= $e($m) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-secondary" type="submit">Save</button>
  </form>
  <div class="tablewrap"><table class="list">
    <thead><tr><th>Person</th><th class="right">Allowance</th><th class="right">Approved</th><th class="right">Waiting</th><th class="right">Left</th><th class="right">Sickness in 12 months</th><th>Set allowance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $pe): $b = $pe['balance']; ?>
      <tr>
        <td><a href="/people/<?= $e($pe['uuid']) ?>"><?= $e($pe['first_name'] . ' ' . $pe['last_name']) ?></a></td>
        <td class="right"><?= $b['allowance'] === null ? '—' : $e($fmt($b['allowance'])) ?></td>
        <td class="right"><?= $e($fmt($b['booked'])) ?></td><td class="right"><?= $e($fmt($b['pending'])) ?></td>
        <td class="right"><?= $b['remaining'] === null ? '—' : $e($fmt($b['remaining'])) ?></td>
        <td class="right"><?= (int) $pe['sick']['spells'] ?> spell<?= (int) $pe['sick']['spells'] === 1 ? '' : 's' ?>, <?= $e($fmt($pe['sick']['days'])) ?> day<?= (float) $pe['sick']['days'] === 1.0 ? '' : 's' ?></td>
        <td><form method="post" action="/leave/allowance" class="row-actions"><?= Csrf::field() ?><input type="hidden" name="person" value="<?= $e($pe['uuid']) ?>"><label class="sr" for="al_<?= $e($pe['uuid']) ?>">Days for <?= $e($pe['first_name'] . ' ' . $pe['last_name']) ?></label><input id="al_<?= $e($pe['uuid']) ?>" name="days" type="number" step="0.5" min="0" max="366" required value="<?= $b['allowance'] === null ? '' : $e($fmt($b['allowance'])) ?>"><button class="btn btn-secondary" type="submit">Save</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="small muted">Allowances are for the leave year in progress. For part-time staff, enter their pro-rata allowance: Care Core does not work it out. Sickness figures are facts for a conversation (how many separate absences and how many working days), not a score.</p>
</section>
