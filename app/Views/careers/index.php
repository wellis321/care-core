<?php use App\Core\View; $e = [View::class, 'e']; ?>
<div class="pagehead"><div><p class="eyebrow">Work with us</p><h1>Jobs at <?= $e($org['name']) ?></h1></div></div>
<?php if (!$vacancies): ?>
<section class="card section"><p>There are no jobs open for applications right now. Please check again soon.</p></section>
<?php else: ?>
<section class="card section">
  <ul class="plainlist">
  <?php foreach ($vacancies as $v): ?>
    <li class="section">
      <h2><a href="/careers/<?= $e($org['slug']) ?>/<?= $e($v['uuid']) ?>"><?= $e($v['title']) ?></a></h2>
      <p class="muted small"><?= $e(implode(' · ', array_filter([$v['location_text'], ucfirst(str_replace('_', ' ', (string) $v['contract_type'])), $v['hours_per_week'] ? rtrim(rtrim((string) $v['hours_per_week'], '0'), '.') . ' hours a week' : null, $v['salary_text'], $v['closing_date'] ? 'Closes ' . View::date($v['closing_date']) : null]))) ?></p>
    </li>
  <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
