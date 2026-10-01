<?php

declare(strict_types=1);

/*
 * Calendrier annuel (bureau du personnel) :
 * - fêtes religieuses, dont la date dépend de l'observation du croissant lunaire ;
 * - horaires particuliers (ramadan, fixé chaque année par arrêté ; horaire d'été…).
 */

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db, 'personnel');
$annee = param_entier('annee') ?? (int) (new DateTimeImmutable())->format('Y');
$heureValide = fn (string $h) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h);

if (est_post()) {
    Csrf::verifier();
    try {
        switch (param('action')) {
            case 'ajouter_ferie':
                $date = date_valide(param('date')) ?? throw new ErreurMetier('Date invalide.');
                $nom = mb_substr(param('nom'), 0, 100);
                if ($nom === '') {
                    throw new ErreurMetier('Indiquez le nom de la fête.');
                }
                if (isset(Calendrier::feriesFixes((int) $date->format('Y'))[$date->format('Y-m-d')])) {
                    throw new ErreurMetier('Cette date est déjà une fête nationale.');
                }
                $db->prepare('DELETE FROM feries_variables WHERE date_ferie = ?')->execute([$date->format('Y-m-d')]);
                $db->prepare('INSERT INTO feries_variables (date_ferie, nom) VALUES (?, ?)')->execute([$date->format('Y-m-d'), $nom]);
                flash('succes', 'Jour férié enregistré.');
                break;
            case 'supprimer_ferie':
                $db->prepare('DELETE FROM feries_variables WHERE date_ferie = ?')->execute([param('date')]);
                flash('succes', 'Jour férié supprimé.');
                break;
            case 'ajouter_horaire':
                $debut = date_valide(param('date_debut'));
                $fin = date_valide(param('date_fin'));
                if ($debut === null || $fin === null || $fin < $debut) {
                    throw new ErreurMetier('Période invalide.');
                }
                if (!$heureValide(param('heure_debut')) || !$heureValide(param('heure_fin')) || param('heure_fin') <= param('heure_debut')) {
                    throw new ErreurMetier('Horaires invalides.');
                }
                $pause = param_entier('pause') ?? 0;
                if ($pause < 0 || $pause > 180 || param('nom') === '') {
                    throw new ErreurMetier('Nom ou durée de pause invalide.');
                }
                $db->prepare('INSERT INTO horaires_speciaux (nom, date_debut, date_fin, heure_debut, heure_fin, pause_minutes)
                              VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([mb_substr(param('nom'), 0, 100), $debut->format('Y-m-d'), $fin->format('Y-m-d'),
                        param('heure_debut'), param('heure_fin'), $pause]);
                flash('succes', 'Horaire particulier enregistré.');
                break;
            case 'supprimer_horaire':
                $db->prepare('DELETE FROM horaires_speciaux WHERE id = ?')->execute([param_entier('id')]);
                flash('succes', 'Horaire particulier supprimé.');
                break;
        }
    } catch (ErreurMetier $e) {
        flash('erreur', $e->getMessage());
    }
    rediriger('calendrier.php?annee=' . $annee);
}

$variables = $db->prepare('SELECT date_ferie, nom FROM feries_variables WHERE date_ferie >= ? AND date_ferie <= ? ORDER BY date_ferie');
$variables->execute(["$annee-01-01", "$annee-12-31"]);
$variables = $variables->fetchAll(PDO::FETCH_KEY_PAIR);
$horaires = $db->prepare('SELECT * FROM horaires_speciaux WHERE date_fin >= ? AND date_debut <= ? ORDER BY date_debut');
$horaires->execute(["$annee-01-01", "$annee-12-31"]);
$horaires = $horaires->fetchAll();

entete('Calendrier', $u, 'calendrier.php');
?>
<div class="titre-page">
    <h1>Calendrier <?= $annee ?></h1>
    <nav class="pagination" aria-label="Changer d'année">
        <a class="bouton" href="<?= e(url('calendrier.php?annee=' . ($annee - 1))) ?>">← <?= $annee - 1 ?></a>
        <a class="bouton" href="<?= e(url('calendrier.php?annee=' . ($annee + 1))) ?>"><?= $annee + 1 ?> →</a>
    </nav>
</div>

<div class="grille grille-2">
    <section class="carte">
        <h2>Jours fériés</h2>
        <table class="tableau">
            <thead><tr><th>Date</th><th>Fête</th><th></th></tr></thead>
            <tbody>
            <?php $tous = Calendrier::feriesFixes($annee) + $variables; ksort($tous); foreach ($tous as $date => $nom):
                $jour = new DateTimeImmutable($date); ?>
                <tr>
                    <td><?= e(ucfirst(mb_substr(NOMS_JOURS[(int) $jour->format('N')], 0, 3)) . ' ' . $jour->format('d/m')) ?></td>
                    <td><?= e($nom) ?> <?= isset($variables[$date]) ? '<span class="pastille pastille-en_attente">religieuse</span>' : '' ?></td>
                    <td>
                        <?php if (isset($variables[$date])): ?>
                            <form method="post" data-confirmer="Supprimer ce jour férié ?">
                                <?= Csrf::champ() ?><input type="hidden" name="action" value="supprimer_ferie">
                                <input type="hidden" name="date" value="<?= e($date) ?>">
                                <button class="bouton bouton-discret" type="submit">Supprimer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="aide">Les fêtes nationales sont calculées automatiquement. Les fêtes religieuses suivent le calendrier
            lunaire : saisissez-les dès l'annonce officielle de leur date.</p>
        <form method="post" class="formulaire">
            <?= Csrf::champ() ?><input type="hidden" name="action" value="ajouter_ferie">
            <div class="ligne-champs">
                <label>Date <input type="date" name="date" required></label>
                <label>Fête <input name="nom" list="fetes" required maxlength="100"></label>
            </div>
            <datalist id="fetes">
                <option value="Aïd al-Fitr"><option value="Aïd al-Adha"><option value="1er Moharram"><option value="Aïd al-Mawlid">
            </datalist>
            <button class="bouton bouton-principal" type="submit">Ajouter le jour férié</button>
        </form>
    </section>

    <section class="carte">
        <h2>Horaires particuliers</h2>
        <p>Horaire normal : <?= e(Horaires::libelle(config('horaires.standard'))) ?>, pause de <?= (int) config('horaires.standard.pause') ?> min
            (<?= (int) config('horaires.vendredi.pause') ?> min le vendredi).</p>
        <?php if ($horaires): ?>
        <table class="tableau">
            <thead><tr><th>Période</th><th>Horaire</th><th class="nombre">Pause</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($horaires as $h): ?>
                <tr>
                    <td><strong><?= e($h['nom']) ?></strong><br><span class="petit">du <?= e(date_courte($h['date_debut'])) ?> au <?= e(date_courte($h['date_fin'])) ?></span></td>
                    <td><?= e(Horaires::libelle(['debut' => $h['heure_debut'], 'fin' => $h['heure_fin']])) ?></td>
                    <td class="nombre"><?= (int) $h['pause_minutes'] ?> min</td>
                    <td>
                        <form method="post" data-confirmer="Supprimer cet horaire ?">
                            <?= Csrf::champ() ?><input type="hidden" name="action" value="supprimer_horaire">
                            <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                            <button class="bouton bouton-discret" type="submit">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p class="vide">Aucun horaire particulier en <?= $annee ?>.</p>
        <?php endif; ?>
        <form method="post" class="formulaire">
            <?= Csrf::champ() ?><input type="hidden" name="action" value="ajouter_horaire">
            <label>Nom <input name="nom" placeholder="Ramadan <?= $annee ?>" required maxlength="100"></label>
            <div class="ligne-champs">
                <label>Du <input type="date" name="date_debut" required></label>
                <label>Au <input type="date" name="date_fin" required></label>
            </div>
            <div class="ligne-champs">
                <label>Début <input type="time" name="heure_debut" value="09:00" required></label>
                <label>Fin <input type="time" name="heure_fin" value="15:00" required></label>
                <label>Pause (min) <input type="number" name="pause" value="0" min="0" max="180" required></label>
            </div>
            <button class="bouton bouton-principal" type="submit">Ajouter l'horaire</button>
            <p class="aide">Pendant le ramadan, l'horaire est fixé chaque année par arrêté (décret n° 2-05-916, art. 2).</p>
        </form>
    </section>
</div>
<?php pied();
