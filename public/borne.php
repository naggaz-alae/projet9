<?php

declare(strict_types=1);

/*
 * Borne de badgeage (tablette ou PC à l'accueil) : pas de compte ouvert,
 * l'employé s'identifie à chaque badge avec son matricule et son code PIN.
 */

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();

if (est_post()) {
    Csrf::verifier();
    $maintenant = new DateTimeImmutable();
    $type = param('type');
    try {
        $employe = Auth::verifierPin($db, param('matricule'), (string) ($_POST['pin'] ?? ''), $maintenant);
        (new PointageService($db))->badger((int) $employe['id'], $type, 'borne', $maintenant);
        flash('succes', sprintf('%s %s — badge « %s » enregistré à %s', $type === 'sortie' ? 'Au revoir' : 'Bonjour',
            $employe['prenom'], Pointage::LIBELLES[$type], $maintenant->format('H:i')));
    } catch (ErreurMetier $e) {
        flash('erreur', $e->getMessage());
    }
    rediriger('borne.php');   // modèle POST → redirection → GET : pas de double badge en actualisant
}

entete('Borne de badgeage', null, '', 'page-borne');
?>
<section class="borne" data-borne>
    <p class="borne-date"><?= e(ucfirst(date_longue(new DateTimeImmutable()))) ?></p>
    <p class="horloge horloge-geante" data-horloge><?= e(date('H:i:s')) ?></p>

    <form method="post" class="carte formulaire borne-formulaire" autocomplete="off">
        <?= Csrf::champ() ?>
        <div class="ligne-champs">
            <label>Matricule <input name="matricule" required autofocus autocapitalize="characters" data-focus-borne></label>
            <label>Code PIN <input type="password" name="pin" inputmode="numeric" pattern="\d{4,6}" required></label>
        </div>
        <div class="borne-actions">
            <?php foreach (Pointage::LIBELLES as $action => $libelle): ?>
                <button type="submit" name="type" value="<?= e($action) ?>" class="bouton bouton-badge action-<?= e($action) ?>"><?= e($libelle) ?></button>
            <?php endforeach; ?>
        </div>
    </form>
    <p class="aide"><a href="<?= e(url('connexion.php')) ?>">Espace personnel</a></p>
</section>
<?php pied();
