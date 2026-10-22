<?php use App\Core\View; $e = [View::class, 'e']; ?>
<p class="crumbs"><a href="/docs">Documentation</a> › <?= $e($groups[$group]['title']) ?> › <?= $e($title) ?></p>
<div class="docs">
  <nav class="docs__nav" aria-label="Documentation">
    <form method="get" action="/docs" class="searchbar" role="search"><label class="sr" for="dq">Search the documentation</label><input id="dq" type="search" name="q" placeholder="Search the docs"><button class="btn btn-secondary" type="submit">Search</button></form>
    <?php foreach ($groups as $g): ?>
      <h2><?= $e($g['title']) ?></h2>
      <ul><?php foreach ($g['pages'] as $s => [$t]): ?><li><a href="/docs/<?= $e($s) ?>"<?= $s === $slug ? ' aria-current="page"' : '' ?>><?= $e($t) ?></a></li><?php endforeach; ?></ul>
    <?php endforeach; ?>
  </nav>
  <article class="prose">
    <h1><?= $e($title) ?></h1>
    <p class="lede"><?= $e($summary) ?></p>
    <?= $body ?>
    <div class="pager">
      <?php if ($prev): ?><a href="/docs/<?= $e($prev[0]) ?>"><small>Previous</small>‹ <?= $e($prev[1]) ?></a><?php else: ?><span></span><?php endif; ?>
      <?php if ($next): ?><a href="/docs/<?= $e($next[0]) ?>" style="text-align:right"><small>Next</small><?= $e($next[1]) ?> ›</a><?php endif; ?>
    </div>
  </article>
</div>
