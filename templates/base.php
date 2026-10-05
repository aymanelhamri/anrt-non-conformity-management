<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'ANRT · Qualité') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/"><span class="brand-mark">A</span><span>ANRT <small>Gestion de la qualité</small></span></a>
    <?php if ($user): ?>
        <nav aria-label="Navigation principale">
            <a href="/non-conformites">Non-conformités</a>
            <span class="identity"><?= e($user['nom_prenom'] ?: $user['matricule']) ?></span>
            <form method="post" action="/deconnexion">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <button class="button quiet" type="submit">Se déconnecter</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<?php if ($config['demo']): ?>
    <div class="demo-banner">Démonstration · Les permissions et règles de saisie utilisées ici sont provisoires.</div>
<?php endif; ?>
<main id="main" class="container">
    <?php if (isset($_SESSION['flash'])): ?>
        <div class="notice success" role="status"><?= e($_SESSION['flash']) ?></div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
    <?= $content /* HTML des templates ; les valeurs variables sont échappées avec e(). */ ?>
</main>
<footer class="container footer">ANRT · Suivi des non-conformités</footer>
</body>
</html>
