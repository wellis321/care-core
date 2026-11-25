<?php use App\Core\View; $e = [View::class, 'e']; $o = fn($k) => $e($old[$k] ?? ''); ?>
<p class="crumbs"><a href="/careers/<?= $e($org['slug']) ?>">All jobs at <?= $e($org['name']) ?></a></p>
<div class="pagehead"><div><p class="eyebrow">Job</p><h1><?= $e($v['title']) ?></h1>
<p class="muted"><?= $e(implode(' · ', array_filter([$v['location_text'], ucfirst(str_replace('_', ' ', (string) $v['contract_type'])), $v['hours_per_week'] ? rtrim(rtrim((string) $v['hours_per_week'], '0'), '.') . ' hours a week' : null, $v['salary_text'], $v['closing_date'] ? 'Closes ' . View::date($v['closing_date']) : null]))) ?></p></div></div>
<div class="split">
<div>
  <section class="card section">
    <?php if ($v['public_summary']): ?><h2>About the job</h2><?= nl2br($e($v['public_summary'])) ?><?php endif; ?>
    <?php if (!empty($v['role']['description'])): ?><h2>What you would do</h2><?= nl2br($e($v['role']['description'])) ?><?php endif; ?>
    <?php if (!empty($v['role']['person_specification'])): ?><h2>What we are looking for</h2><?= nl2br($e($v['role']['person_specification'])) ?><?php endif; ?>
    <?php if (!$v['public_summary'] && empty($v['role']['description'])): ?><p class="muted">Apply below and we will be in touch with more detail.</p><?php endif; ?>
  </section>
</div>
<aside>
  <section class="card section" id="apply">
    <h2>Apply</h2>
    <?php if ($error): ?><div class="alert alert-error" role="alert"><?= $e($error) ?></div><?php endif; ?>
    <form class="form" method="post" action="/careers/<?= $e($org['slug']) ?>/<?= $e($v['uuid']) ?>/apply">
      <div class="form-group"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" required maxlength="100" autocomplete="given-name" value="<?= $o('first_name') ?>"></div>
      <div class="form-group"><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" required maxlength="100" autocomplete="family-name" value="<?= $o('last_name') ?>"></div>
      <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= $o('email') ?>"><span class="hint">We reply here.</span></div>
      <div class="form-group"><label for="phone">Phone (optional)</label><input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= $o('phone') ?>"></div>
      <div class="form-group"><label for="statement">Tell us a little about yourself and why you are applying (optional)</label><textarea id="statement" name="statement" rows="6" maxlength="3000"><?= $o('statement') ?></textarea></div>
      <div aria-hidden="true" style="position:absolute;left:-9999px"><label for="hp_extra">Leave this empty</label><input id="hp_extra" name="hp_extra" type="text" tabindex="-1" autocomplete="off"></div>
      <input type="hidden" name="ft" value="<?= $e($stamp) ?>">
      <p class="small muted">We keep what you send here only to consider your application and to contact you about it. You can ask us to remove it at any time.</p>
      <button class="btn btn-primary" type="submit">Send application</button>
    </form>
  </section>
</aside>
</div>
