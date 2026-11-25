<?php use App\Core\Csrf; use App\Core\View; $e = [View::class, 'e'];
$when = date('l j F Y \a\t H:i', strtotime((string) $i['starts_at'])); ?>
<div class="pagehead"><div><p class="eyebrow">Your interview</p><h1><?= $e($a['title'] ?? 'Interview') ?></h1><p class="muted"><?= $e($orgName) ?></p></div></div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<?php if ($sent): ?><div class="alert alert-success" role="status">Thank you. We have your answer.</div><?php endif; ?>
<section class="card section">
  <?php if ($i['status'] === 'cancelled'): ?><div class="alert alert-info">This interview has been cancelled. The organisation will contact you.</div><?php endif; ?>
  <dl class="facts">
    <dt>When</dt><dd><?= $e($when) ?> (about <?= (int) $i['duration_minutes'] ?> minutes)</dd>
    <dt>Where</dt><dd><?= $e($i['location']) ?></dd>
    <?php if ($i['panel']): ?><dt>You will meet</dt><dd><?= $e($i['panel']) ?></dd><?php endif; ?>
    <?php if ($i['instructions']): ?><dt>Please bring</dt><dd><?= $e($i['instructions']) ?></dd><?php endif; ?>
    <dt>Your answer</dt><dd><?= ['none' => 'Not answered yet', 'confirmed' => 'You said you can come', 'cannot_attend' => 'You said you cannot come'][$i['candidate_response']] ?><?= $i['response_note'] ? ': ' . $e($i['response_note']) : '' ?></dd>
  </dl>
</section>
<?php if ($open): ?>
<section class="card section">
  <h2>Can you come?</h2>
  <form class="form" method="post" action="/interview/<?= $e($i['public_token']) ?>">
    <div class="form-group"><label for="note">A message (optional)</label><textarea id="note" name="note" rows="3" maxlength="500"></textarea></div>
    <button class="btn btn-primary" type="submit" name="response" value="confirmed">Yes, I can come</button>
    <button class="btn btn-secondary" type="submit" name="response" value="cannot_attend">No, I cannot come</button>
  </form>
</section>
<?php endif; ?>
