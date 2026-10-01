<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
if (Auth::utilisateur($db)) {
    rediriger('tableau-de-bord.php');
}

$erreur = null;
$email = '';
if (est_post()) {
    Csrf::verifier();
    $email = param('email');
    try {
        Auth::ouvrirSession(Auth::verifier($db, $email, (string) ($_POST['mot_de_passe'] ?? ''), new DateTimeImmutable()));
        rediriger('tableau-de-bord.php');
    } catch (ErreurMetier $e) {
        $erreur = $e->getMessage();
    }
}

entete('Connexion', null, '', 'page-connexion');
?>
<section class="connexion">
    <div class="connexion-marque">
        <img src="<?= e(url('assets/icons/icone.svg')) ?>" alt="" width="56" height="56">
        <h1><?= e(config('nom_app')) ?></h1>
        <p>Badgeage et congés dans l'administration publique.</p>
        <p class="contexte"><?= e(config('contexte')) ?>.</p>
    </div>

    <form method="post" class="carte formulaire" novalidate>
        <?= Csrf::champ() ?>
        <?php if ($erreur): ?><div class="alerte alerte-erreur" role="alert"><?= e($erreur) ?></div><?php endif; ?>
        <label>Adresse e-mail
            <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
        </label>
        <label>Mot de passe
            <input type="password" name="mot_de_passe" autocomplete="current-password" required>
        </label>
        <button type="submit" class="bouton bouton-principal bouton-large">Se connecter</button>
        <p class="aide"><a href="<?= e(url('borne.php')) ?>">Badger depuis la borne d'accueil →</a></p>
    </form>

    <?php if (config('demo')): ?>
    <aside class="carte demo">
        <h2>Comptes de démonstration</h2>
        <p>Mot de passe commun : <code>demo1234</code></p>
        <table>
            <tr><th>Fonctionnaire</th><td><code>fonctionnaire@demo.test</code></td></tr>
            <tr><th>Chef de service</th><td><code>chef@demo.test</code></td></tr>
            <tr><th>Directeur</th><td><code>directeur@demo.test</code></td></tr>
            <tr><th>Bureau du personnel</th><td><code>personnel@demo.test</code></td></tr>
        </table>
        <p class="aide">Borne : matricule <code>F001</code>, code PIN <code>1234</code></p>
    </aside>
    <?php endif; ?>
</section>
<?php pied();
