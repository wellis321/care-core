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
<div class="demo-banner" role="region" aria-label="Demo notice"><strong>Demo</strong> <span class="demo-banner__text">All names and records here are made up, and the data resets every night. Please do not enter real personal information.</span></div>
<?php endif; ?>
<header class="topbar">
  <div class="topbar__in">
    <a class="brand" href="/"><span class="brand__mark" aria-hidden="true">C</span>Care Core</a>
    <?php if ($user): ?>
    <nav class="topnav" aria-label="Main">
      <a href="/"<?= $active('/') ?>>Dashboard</a>
      <?php if (Auth::can('people.view') || Auth::can('people.view_staff')): ?><a href="/people"<?= $active('/people') ?>>People</a><?php endif; ?>
      <?php if (Modules::enabled($org, 'teams') && Auth::can('teams.view')): ?><a href="/teams"<?= $active('/teams') ?>>Teams</a><?php endif; ?>
      <?php if (Auth::can('tasks.view')): ?><a href="/tasks"<?= $active('/tasks') ?>>Tasks</a><?php endif; ?>
      <?php if (Auth::user()['person_id']): ?><a href="/me"<?= $active('/me') ?>>My profile</a><?php endif; ?>
      <?php $waiting = \App\Modules\Staff\ProfileChanges::queueFor($user); if ($waiting || Auth::can('staff.manage')): ?><a href="/approvals"<?= $active('/approvals') ?>>Approvals<?php if ($waiting): ?><span class="badge" aria-label="<?= count($waiting) ?> waiting"><?= count($waiting) ?></span><?php endif; ?></a><?php endif; ?>
      <?php if (Auth::can('audit.view')): ?><a href="/activity"<?= $active('/activity') ?>>Activity</a><?php endif; ?>
      <?php if (Auth::can('*')): ?><a href="/admin"<?= $active('/admin') ?>>Admin</a><?php endif; ?>
    </nav>
    <div class="topbar__user">
      <span class="who"><?= View::e($user['email']) ?></span>
      <form method="post" action="/logout"><?= Csrf::field() ?><button class="linkbtn" type="submit">Sign out</button></form>
    </div>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="container" tabindex="-1">
<?php foreach ($flash as [$type, $msg]): ?>
  <div class="alert alert-<?= $type === 'ok' ? 'success' : 'error' ?>" role="<?= $type === 'ok' ? 'status' : 'alert' ?>"><?= View::e($msg) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="sitefoot"><div class="container">
  <p>Care Core: one record of your people, your teams and the people you support. Joiners, movers and leavers change everything in one go.</p>
</div></footer>
</body>
</html>
