<?php use App\Core\Csrf; use App\Core\View; use App\Modules\Leave\LeaveRepo; $e = [View::class, 'e'];
$st = ['requested' => 'Waiting for a decision', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];
$fmt = static fn($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.'); ?>
<div class="pagehead"><div><p class="eyebrow">Time away</p><h1>Leave</h1><p class="muted">Ask for leave, or tell us you are off sick.<?php if ($isManager): ?> <a href="/leave/team">Team leave and decisions</a><?php endif; ?></p></div></div>
<?php if (!$pid): ?>
<section class="card section"><p>Your login is not linked to a person record, so there is no leave to show.<?php if ($isManager): ?> Go to <a href="/leave/team">team leave</a>.<?php endif; ?></p></section>
<?php else: ?>
<div class="split">
<div>
  <section class="card section">
    <h2>Your leave</h2>
    <?php if ($list): ?>
    <div class="tablewrap"><table class="list">
      <thead><tr><th>What</th><th>When</th><th class="right">Days</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
      <tbody>
      <?php foreach ($list as $a): ?>
        <tr>
          <td><?= $e($kinds[$a['kind']]) ?></td>
          <td><?= View::date($a['starts_on']) ?><?= $a['ends_on'] ? ($a['ends_on'] !== $a['starts_on'] ? ' to ' . View::date($a['ends_on']) : '') : ' (still off)' ?></td>
          <td class="right"><?= $a['days'] !== null ? $e($fmt($a['days'])) : '—' ?></td>
          <td><span class="chip"><?= $e($st[$a['status']]) ?></span><?= $a['decision_note'] ? '<br><span class="muted small">' . $e($a['decision_note']) . '</span>' : '' ?></td>
          <td class="right">
            <?php if ($a['kind'] === 'sickness' && $a['status'] === 'approved' && $a['ends_on'] === null): ?>
              <form method="post" action="/leave/<?= $e($a['uuid']) ?>/back" class="row-actions"><?= Csrf::field() ?><label class="sr" for="back_<?= $e($a['uuid']) ?>">Last day off</label><input id="back_<?= $e($a['uuid']) ?>" type="date" name="ends_on" max="<?= $e($today) ?>" value="<?= $e($today) ?>"><button class="btn btn-secondary" type="submit">I am back</button></form>
            <?php elseif ($a['kind'] !== 'sickness' && ($a['status'] === 'requested' || ($a['status'] === 'approved' && $a['starts_on'] > $today))): ?>
              <form method="post" action="/leave/<?= $e($a['uuid']) ?>/cancel" class="row-actions"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit">Cancel</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?><p class="muted">Nothing yet.</p><?php endif; ?>
  </section>
  <?php if ($swaps): ?><section class="card section" id="swaps"><h2>Leave swaps</h2>
  <?php foreach ($swaps as $w): $toMe = (int) $w['to_person_id'] === (int) $pid; ?>
    <div class="section"><p><?php if ($toMe): ?><strong><?= $e($w['f_first'] . ' ' . $w['f_last']) ?></strong> asks to swap their <?= View::date($w['f_start']) ?> to <?= View::date($w['f_end']) ?> for your <?= View::date($w['t_start']) ?> to <?= View::date($w['t_end']) ?><?php else: ?>You asked <strong><?= $e($w['t_first'] . ' ' . $w['t_last']) ?></strong> to swap your <?= View::date($w['f_start']) ?> to <?= View::date($w['f_end']) ?> for their <?= View::date($w['t_start']) ?> to <?= View::date($w['t_end']) ?><?php endif; ?>
    · <span class="chip"><?= $w['status'] === 'accepted' ? 'Waiting for a manager' : 'Waiting for an answer' ?></span></p>
    <?php if ($w['note']): ?><p class="muted small">“<?= $e($w['note']) ?>”</p><?php endif; ?>
    <?php if ($toMe && $w['status'] === 'requested'): ?><form method="post" action="/leave/swap/<?= $e($w['uuid']) ?>/answer" class="row-actions"><?= Csrf::field() ?><button class="btn btn-primary" name="answer" value="yes" type="submit">Yes, swap</button> <button class="btn btn-secondary" name="answer" value="no" type="submit">No</button></form><?php endif; ?>
    <?php if (!$toMe): ?><form method="post" action="/leave/swap/<?= $e($w['uuid']) ?>/cancel" class="row-actions"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit">Withdraw</button></form><?php endif; ?></div>
  <?php endforeach; ?></section><?php endif; ?>
</div>
<aside>
  <section class="card section">
    <h2>Annual leave this year</h2>
    <p class="muted small"><?= View::date($balance['year_start']) ?> to <?= View::date($balance['year_end']) ?></p>
    <?php if ($balance['allowance'] === null): ?>
      <p>No allowance has been set for you yet. A manager sets it.</p>
      <p class="small muted">Approved so far: <?= $e($fmt($balance['booked'])) ?> days. Waiting: <?= $e($fmt($balance['pending'])) ?>.</p>
    <?php else: ?>
    <dl class="facts"><dt>Allowance</dt><dd><?= $e($fmt($balance['allowance'])) ?> days</dd><dt>Approved</dt><dd><?= $e($fmt($balance['booked'])) ?> days</dd><dt>Waiting for a decision</dt><dd><?= $e($fmt($balance['pending'])) ?> days</dd><dt>Left</dt><dd><strong><?= $e($fmt($balance['remaining'])) ?> days</strong></dd></dl>
    <?php endif; ?>
    <p class="small muted">Days are Monday to Friday. Bank holidays are not taken off automatically: your manager can correct the number when they decide.</p>
  </section>
  <section class="card section">
    <h2>Ask for leave</h2>
    <?php if ($canSwap): ?><p class="small"><a href="/leave/swap">Swap annual leave with a colleague instead</a></p><?php endif; ?>
    <form class="form" method="post" action="/leave"><?= Csrf::field() ?>
      <div class="form-group"><label for="kind">What for</label><select id="kind" name="kind"><?php foreach ($requestable as $k): ?><option value="<?= $e($k) ?>"><?= $e($kinds[$k]) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label for="starts_on">First day</label><input id="starts_on" name="starts_on" type="date" required min="<?= $e($today) ?>"></div>
      <div class="form-group"><label class="check"><input type="checkbox" name="start_half" value="1"> First day is a half day</label></div>
      <div class="form-group"><label for="ends_on">Last day</label><input id="ends_on" name="ends_on" type="date" required min="<?= $e($today) ?>"></div>
      <div class="form-group"><label class="check"><input type="checkbox" name="end_half" value="1"> Last day is a half day</label></div>
      <div class="form-group"><label for="reason">Anything the manager should know (optional)</label><input id="reason" name="reason" type="text" maxlength="500"></div>
      <button class="btn btn-primary" type="submit">Send request</button>
    </form>
  </section>
  <section class="card section">
    <h2>Off sick</h2>
    <form class="form" method="post" action="/leave/sick"><?= Csrf::field() ?>
      <div class="form-group"><label for="s_start">First day off</label><input id="s_start" name="starts_on" type="date" required max="<?= $e($today) ?>" value="<?= $e($today) ?>"></div>
      <div class="form-group"><label for="s_end">Last day off (leave empty if you do not know yet)</label><input id="s_end" name="ends_on" type="date" max="<?= $e($today) ?>"></div>
      <div class="form-group"><label for="s_reason">A short note for your manager (optional)</label><input id="s_reason" name="reason" type="text" maxlength="500"><span class="hint">Your manager can see this. You do not need to give medical detail.</span></div>
      <button class="btn btn-secondary" type="submit">Tell my manager</button>
      <p class="small muted">This does not replace phoning in if your service asks you to.</p>
    </form>
  </section>
</aside>
</div>
<?php endif; ?>
