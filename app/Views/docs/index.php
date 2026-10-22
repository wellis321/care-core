<?php use App\Core\View; $e = [View::class, 'e']; ?>
<div class="hero"><p class="eyebrow">Documentation</p><h1><?= $q !== '' ? 'Search results' : 'Everything Care Core does, explained' ?></h1>
<p class="lede">Guides for the people who use it, a reference for the people who set it up, and the detail developers need to connect other systems.</p>
<form method="get" action="/docs" class="searchbar" role="search"><label class="sr" for="q">Search the documentation</label><input id="q" type="search" name="q" value="<?= $e($q) ?>" placeholder="Search, for example: leaver, signature, SCIM, webhook"><button class="btn btn-primary" type="submit">Search</button></form></div>
<?php if ($q !== ''): ?>
  <?php if (!$hits): ?><p class="empty">Nothing matched “<?= $e($q) ?>”. Try fewer or different words, or browse the sections below.</p>
  <?php else: ?><p class="muted"><?= count($hits) ?> page<?= count($hits) === 1 ? '' : 's' ?> match.</p>
  <div class="doccards"><?php foreach ($hits as $h): ?><a href="/docs/<?= $e($h['slug']) ?>"><strong><?= $e($h['title']) ?></strong><span class="muted small"><?= $e($h['snippet']) ?></span></a><?php endforeach; ?></div><?php endif; ?>
  <h2 style="margin-top:2.5rem">Browse</h2>
<?php endif; ?>
<?php foreach ($groups as $g): ?>
  <section class="section"><h2><?= $e($g['title']) ?></h2><p class="muted"><?= $e($g['blurb']) ?></p>
  <div class="doccards"><?php foreach ($g['pages'] as $s => [$t, $sum]): ?><a href="/docs/<?= $e($s) ?>"><strong><?= $e($t) ?></strong><span class="muted small"><?= $e($sum) ?></span></a><?php endforeach; ?></div></section>
<?php endforeach; ?>
