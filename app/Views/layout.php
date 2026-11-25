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
$marketing = !empty($marketing);   // the public front page: its own header, full width
$flash = Session::takeFlash();
$reveal = Session::takeReveal();
?><!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($marketing ? 'Care Core: one record of your people. Change it once.' : (($title ?? 'Care Core') . ' · Care Core')) ?></title>
<?php if ($marketing): ?><meta name="description" content="Care Core is the system of record for a care organisation: staff, the people they support and the teams between them. Joiners, movers and leavers change everything in one go. A working demonstration.">
<meta property="og:title" content="Care Core: one record of your people. Change it once."><meta property="og:type" content="website">
<link rel="preload" href="/assets/fonts/newsreader-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<?php else: ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) @filemtime(ROOT_PATH . '/public/assets/app.css') ?>">
<link rel="stylesheet" href="/assets/suite/suite.css?v=<?= (int) @filemtime(ROOT_PATH . '/public/assets/suite/suite.css') ?>">
<?php if ($marketing): ?><link rel="stylesheet" href="/assets/home.css?v=<?= (int) @filemtime(ROOT_PATH . '/public/assets/home.css') ?>"><?php endif; ?>
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<?php if (Config::demo()): ?>
<div class="demo-banner" role="region" aria-label="Demo notice"><strong>Demo</strong> <span class="demo-banner__text">All names and records here are made up, and the data resets every night. Please do not enter real personal information.</span> <strong><a class="demo-banner__back" href="https://findcarescotland.com/software">&larr; Back to Find Care Scotland software</a></strong> &middot; <a href="https://findcarescotland.com/software">All software</a>
<?php if ($user): $other = ($user['role'] ?? '') === 'staff' ? 'manager' : 'staff'; ?>
<form method="post" action="/demo/enter" class="demo-switch"><?= \App\Core\Csrf::field() ?><?= \App\Modules\Identity\DemoEntry::fields() ?><span>You are viewing as <?= htmlspecialchars(['staff' => 'a member of staff', 'manager' => 'a manager', 'admin' => 'an administrator'][$user['role']] ?? 'a user') ?>.</span> <button class="linkbtn" type="submit" name="role" value="<?= $other ?>">See it as <?= $other === 'staff' ? 'a member of staff' : 'a manager' ?></button></form>
<?php endif; ?></div>
<?php endif; ?>
<?php if ($marketing): ?>
<header class="rg-top"><div class="rg-top-in">
  <a class="rg-brand" href="/"><span class="rg-mark" aria-hidden="true">C</span>Care Core</a>
  <nav class="rg-nav" aria-label="Main"><a href="#story">What it does</a><a href="#lifecycle">Joiners and leavers</a><a href="#hub">Joined up</a><a href="#trust">Security</a><a href="#developers">For developers</a><a href="#faq">Questions</a></nav>
  <div class="rg-top-cta"><a class="rg-signin" href="/login">Sign in</a><a class="rg-btn rg-btn-ink rg-btn-sm" href="#demo">Try the demo</a></div>
</div></header>
<?php else: ?>
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
      <?php if (Auth::can('tasks.view')): ?><a href="/my-work"<?= $active('/my-work') ?>>My work</a><?php endif; ?>
      <?php if (Auth::can('people.view') || Auth::can('people.view_staff')): ?><a href="/people"<?= $active('/people') ?>>People</a><?php endif; ?>
      <?php if (Modules::enabled($org, 'teams') && Auth::can('teams.view')): ?><a href="/teams"<?= $active('/teams') ?>>Teams</a><?php endif; ?>
      <?php if (Modules::enabled($org, 'recruitment') && Auth::can('recruitment.manage')): ?><a href="/recruitment"<?= $active('/recruitment') ?>>Recruitment</a><?php endif; ?>
      <?php if (Modules::enabled($org, 'referrals') && Auth::can('referrals.manage')): ?><a href="/referrals"<?= $active('/referrals') ?>>Referrals</a><?php endif; ?>
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
<?php endif; ?>
<main id="main"<?= $marketing ? '' : ' class="container"' ?> tabindex="-1">
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
<?php if ($marketing): ?><script src="/assets/home.js" defer></script><?php endif; ?>
<footer class="sitefoot"><div class="container">
  <p>Care Core: one record of your people, your teams and the people you support. Joiners, movers and leavers change everything in one go.</p>
  <?php if (Config::demo()): ?>
  <p class="demo-other-products">Other products: <?php
    $links = [];
    foreach (['digital-id' => 'Digital ID', 'contracts' => 'Contracts', 'strategic-plan' => 'Strategic plans', 'simple-data-cleaner' => 'Simple Data Cleaner'] as $slug => $label) {
        $links[] = '<a href="https://findcarescotland.com/software/' . $slug . '">' . $label . '</a>';
    }
    echo implode(' · ', $links); ?> · <a href="https://findcarescotland.com/software">All software for providers</a> · <a href="https://docs.findcarescotland.com/products/care-core.html">Documentation</a> · <a href="https://findcarescotland.com/">Find Care Scotland directory</a></p>
  <?php endif; ?>
  <p class="footlinks"><a href="/about">About</a> · <a href="/features">Features</a> · <a href="/docs">Documentation</a> · <a href="/security">Security and privacy</a> · <a href="/docs/faq">Questions</a></p>
</div></footer>
</body>
</html>
