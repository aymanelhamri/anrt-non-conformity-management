<?php $title = 'Connexion · ANRT'; ?>
<section class="login-layout">
    <div class="login-intro">
        <p class="eyebrow">ESPACE QUALITÉ</p>
        <h1>Déclarez une non-conformité.</h1>
        <p>Enregistrez un constat, renseignez sa prise en charge et obtenez sa référence.</p>
        <div class="intro-note">Connexion · Déclaration · Confirmation</div>
    </div>
    <div class="card login-card">
        <h2>Connexion</h2><p class="muted">Connectez-vous avec votre compte personnel.</p>
        <?php if ($error): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label for="matricule">Matricule</label>
            <input id="matricule" name="matricule" maxlength="60" value="<?= e($identifier) ?>" autocomplete="username" required autofocus>
            <label for="password">Mot de passe</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
            <button class="button primary full" type="submit">Se connecter</button>
        </form>
        <p class="muted small">Votre accès dépend de la période de validité de votre compte.</p>
    </div>
</section>
