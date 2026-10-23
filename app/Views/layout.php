<?php
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Modules;
use App\Core\Session;
use App\Core\View;

$e = [View::class, 'e'];
$user = Auth::user();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$active = static fn(string $prefix): string => ($prefix === '/' ? $path === '/' : str_starts_with($path, $prefix)) ? ' aria-current="page"' : '';
$org = $user ? (int) $user['organisation_id'] : 0;
$flash = Session::takeFlash();
$reveal = Session::takeReveal();
?><!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'Care Core') ?> · Care Core</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) @filemtime(ROOT_PATH . '/public/assets/app.css') ?>">
<link rel="stylesheet" href="/assets/suite/suite.css?v=<?= (int) @filemtime(ROOT_PATH . '/public/assets/suite/suite.css') ?>">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<?php if (Config::demo()): ?>
<div class="demo-banner" role="region" aria-label="Demo notice"><strong>Demo</strong> <span class="demo-banner__text">All names and records here are made up, and the data resets every night. Please do not enter real personal information.</span> <a href="https://findcarescotland.com/software">Part of Find Care Scotland’s software for providers</a></div>
<?php endif; ?>
<header class="topbar">
  <div class="topbar__in">
    <a class="brand" href="/"><span class="brand__mark" aria-hidden="true">C</span>Care Core</a>
    <?php if (!$user): ?>
    <nav class="topnav" aria-label="Main">
      <a href="/about"<?= $active('/about') ?>>About</a>
      <a href="/features"<?= $active('/features') ?>>Features</a>
      <a href="/docs"<?= $active('/docs') ?>>Documentation</a>
      <a href="/security"<?= $active('/security') ?>>Security</a>
    </nav>
    <div class="topbar__user"><a class="linkbtn" href="/login">Sign in</a></div>
    <?php endif; ?>
    <?php if ($user): ?>
    <nav class="topnav" aria-label="Main">
      <a href="/"<?= $active('/') ?>>Dashboard</a>
      <?php if (Auth::can('people.view') || Auth::can('people.view_staff')): ?><a href="/people"<?= $active('/people') ?>>People</a><?php endif; ?>
      <?php if (Modules::enabled($org, 'teams') && Auth::can('teams.view')): ?><a href="/teams"<?= $active('/teams') ?>>Teams</a><?php endif; ?>
      <?php if (Modules::enabled($org, 'recruitment') && Auth::can('recruitment.manage')): ?><a href="/recruitment"<?= $active('/recruitment') ?>>Recruitment</a><?php endif; ?>
      <?php if (Auth::can('tasks.view')): ?><a href="/tasks"<?= $active('/tasks') ?>>Tasks</a><?php endif; ?>
      <?php if (Auth::user()['person_id']): ?><a href="/me"<?= $active('/me') ?>>My profile</a><?php endif; ?>
      <?php $waiting = \App\Modules\Staff\ProfileChanges::queueFor($user); if ($waiting || Auth::can('staff.manage')): ?><a href="/approvals"<?= $active('/approvals') ?>>Approvals<?php if ($waiting): ?><span class="badge" aria-label="<?= count($waiting) ?> waiting"><?= count($waiting) ?></span><?php endif; ?></a><?php endif; ?>
      <?php if (Auth::can('audit.view')): ?><a href="/activity"<?= $active('/activity') ?>>Activity</a><?php endif; ?>
      <?php if (Auth::can('*')): ?><a href="/admin"<?= $active('/admin') ?>>Admin</a><?php endif; ?>
      <a href="/docs"<?= $active('/docs') ?>>Help</a>
    </nav>
    <div class="topbar__user">
      <a class="who" href="/account" title="Your account"><?= View::e($user['email']) ?></a>
      <form method="post" action="/logout"><?= Csrf::field() ?><button class="linkbtn" type="submit">Sign out</button></form>
    </div>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="container" tabindex="-1">
<?php if ($reveal): ?>
<section class="reveal" id="new-secret" tabindex="-1" aria-labelledby="reveal-title">
  <h2 id="reveal-title"><?= View::e($reveal['title']) ?></h2>
  <p><?= View::e($reveal['note']) ?></p>
  <div class="reveal__row">
    <label class="sr" for="reveal-value"><?= View::e($reveal['label']) ?></label>
    <input id="reveal-value" class="reveal__value" type="text" readonly value="<?= View::e($reveal['value']) ?>" spellcheck="false" autocomplete="off">
    <button class="btn btn-primary" type="button" id="reveal-copy">Copy</button>
  </div>
  <p class="reveal__status" id="reveal-status" role="status" aria-live="polite"></p>
  <p class="small muted">This is shown only this once. Leaving or reloading the page hides it for good.</p>
</section>
<script src="/assets/copy.js" defer></script>
<?php endif; ?>
<?php foreach ($flash as [$type, $msg]): ?>
  <div class="alert alert-<?= $type === 'ok' ? 'success' : 'error' ?>" role="<?= $type === 'ok' ? 'status' : 'alert' ?>"><?= View::e($msg) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="sitefoot"><div class="container">
  <p>Care Core: one record of your people, your teams and the people you support. Joiners, movers and leavers change everything in one go.</p>
  <?php if (Config::demo()): ?>
  <p class="demo-other-products">Other products: <?php
    $links = [];
    foreach (['digital-id' => 'Digital ID', 'contracts' => 'Contracts', 'strategic-plan' => 'Strategic plans', 'simple-data-cleaner' => 'Simple Data Cleaner'] as $slug => $label) {
        $links[] = '<a href="https://findcarescotland.com/software/' . $slug . '">' . $label . '</a>';
    }
    echo implode(' · ', $links); ?> · <a href="https://findcarescotland.com/software">All software for providers</a> · <a href="https://findcarescotland.com/">Find Care Scotland directory</a></p>
  <?php endif; ?>
  <p class="footlinks"><a href="/about">About</a> · <a href="/features">Features</a> · <a href="/docs">Documentation</a> · <a href="/security">Security and privacy</a> · <a href="/docs/faq">Questions</a></p>
</div></footer>
</body>
</html>
