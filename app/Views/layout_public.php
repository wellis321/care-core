<?php use App\Core\View; ?><!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title ?? 'Jobs') ?></title>
<meta name="robots" content="<?= !empty($index) ? 'index,follow' : 'noindex' ?>">
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) @filemtime(ROOT_PATH . '/public/assets/app.css') ?>">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar"><div class="topbar__in"><span class="brand"><?= View::e($org['name'] ?? ($orgName ?? 'Jobs')) ?></span></div></header>
<main id="main" class="container" tabindex="-1">
<?= $content ?>
</main>
<footer class="sitefoot"><div class="container"><p class="small muted">Applications are handled by <?= View::e($org['name'] ?? ($orgName ?? 'the organisation')) ?>, using Care Core. We use your details only to consider your application and to contact you about it.</p></div></footer>
</body>
</html>
