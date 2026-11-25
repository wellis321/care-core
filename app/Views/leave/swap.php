<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e'];
$fmt = static fn($n) => rtrim(rtrim(number_format((float) $n, 1), '0'), '.');
$span = static fn($a) => View::date($a['starts_on']) . ($a['ends_on'] !== $a['starts_on'] ? ' to ' . View::date($a['ends_on']) : '') . ' · ' . $fmt($a['days']) . ' days'; ?>
<div class="pagehead"><div><p class="eyebrow"><a href="/leave">Leave</a></p><h1>Swap annual leave</h1><p class="muted">Swap one spell of approved annual leave with a colleague. You each keep the number of days you were allowed, so allowances do not change; only the dates change places. Your colleague agrees, then a manager decides.</p></div></div>
<?php if (!$mine): ?><section class="card section"><p>You have no approved annual leave that has not started yet, so there is nothing to swap.</p></section>
<?php elseif (!$colleagues): ?><section class="card section"><p>No colleague has approved annual leave that could be swapped at the moment.</p></section>
<?php else: ?>
<section class="card section"><h2>1. Who with?</h2>
<form class="form" method="get" action="/leave/swap"><div class="form-group"><label for="with">Colleague</label><select id="with" name="with" required><option value="">Choose…</option><?php foreach ($colleagues as $c): ?><option value="<?= $e($c['uuid']) ?>"<?= $with && $with['uuid'] === $c['uuid'] ? ' selected' : '' ?>><?= $e($c['first_name'] . ' ' . $c['last_name']) ?></option><?php endforeach; ?></select></div>
<button class="btn btn-secondary" type="submit">Show their leave</button></form></section>
<?php if ($with): ?>
<section class="card section"><h2>2. Which spells?</h2>
<form class="form" method="post" action="/leave/swap"><?= Csrf::field() ?>
<fieldset class="form-group"><legend>Your leave you would give up</legend>
<?php foreach ($mine as $i => $a): ?><label class="check"><input type="radio" name="mine" value="<?= $e($a['uuid']) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= $span($a) ?></label><?php endforeach; ?></fieldset>
<fieldset class="form-group"><legend>In return for <?= $e($with['first_name']) ?>'s</legend>
<?php if (!$theirs): ?><p class="muted">They have no approved annual leave that can be swapped.</p><?php endif; ?>
<?php foreach ($theirs as $i => $a): ?><label class="check"><input type="radio" name="theirs" value="<?= $e($a['uuid']) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= $span($a) ?></label><?php endforeach; ?></fieldset>
<p class="small muted">The two spells must be the same number of working days. If they are not, you will be told when you send it.</p>
<div class="form-group"><label for="note">A note (optional)</label><input id="note" name="note" type="text" maxlength="300"></div>
<?php if ($theirs): ?><button class="btn btn-primary" type="submit">Ask <?= $e($with['first_name']) ?></button><?php endif; ?></form></section>
<?php endif; ?>
<?php endif; ?>
