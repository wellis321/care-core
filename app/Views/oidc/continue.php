<?php use App\Core\View; ?><!DOCTYPE html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<meta http-equiv="refresh" content="0;url=<?= View::e($to) ?>">
<title>Signing you in · Care Core</title>
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main style="max-width:32rem;margin:4rem auto;padding:0 1rem" id="main">
  <h1>Signing you in to <?= View::e($product) ?></h1>
  <p>You are signed in to Care Core as <strong><?= View::e($who) ?></strong>. Taking you to <?= View::e($product) ?> now.</p>
  <p><a class="btn btn--primary" href="<?= View::e($to) ?>">Continue to <?= View::e($product) ?></a></p>
  <p class="muted small">If this was not you, close this page and <a href="/account">review your account</a>.</p>
</main>
</body>
</html>
