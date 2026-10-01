<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db);
$maintenant = new DateTimeImmutable();
$jour = aujourdhui($maintenant);

$pointages = new PointageService($db);
$evenements = $pointages->evenements((int) $u['id'], $jour);
$etat = Pointage::etat($evenements);
$secondesJour = Pointage::secondesTravaillees($evenements, $maintenant);
$horaire = Horaires::du($jour);

$lundi = lundi_de($jour);
$semaine = $pointages->periode((int) $u['id'], $lundi, $lundi->modify('+6 days'), $maintenant);
$secondesSemaine = array_sum(array_column($semaine, 'secondes'));
$prevuSemaine = array_sum(array_column($semaine, 'prevu'));

$conges = new Conges($db);
$annee = (int) $jour->format('Y');
$soldes = $conges->soldes((int) $u['id'], $annee);
$prochain = $conges->prochainCongeAccorde((int) $u['id'], $jour);

// Vue d'ensemble pour les responsables
$equipe = null;
if ($u['role'] !== 'fonctionnaire') {
    $perimetre = array_values(array_filter((new Employes($db))->perimetre($u), fn (array $e) => (int) $e['id'] !== (int) $u['id']));
    $situation = $pointages->situationDuJour($perimetre, $maintenant);
    $absents = $conges->absences(array_map(fn (array $e) => (int) $e['id'], $perimetre), $jour->format('Y-m-d'), $jour->format('Y-m-d'));
    $enConge = array_flip(array_map(fn (array $a) => (int) $a['employe_id'], $absents));
    $compte = ['present' => 0, 'pause' => 0, 'conge' => count($enConge), 'non_badge' => 0];
    foreach ($situation as $s) {
        if ($s['etat'] === Pointage::PRESENT) {
            $compte['present']++;
        } elseif ($s['etat'] === Pointage::PAUSE) {
            $compte['pause']++;
        } elseif (!isset($enConge[(int) $s['employe']['id']]) && $s['arrivee'] === null) {
            $compte['non_badge']++;
        }
    }
    $equipe = ['compte' => $compte, 'a_traiter' => count($conges->aTraiter($u)), 'effectif' => count($perimetre)];
}

entete('Tableau de bord', $u, 'tableau-de-bord.php');
?>
<div class="titre-page">
    <div>
        <p class="surtitre"><?= e(ucfirst(date_longue($maintenant))) ?></p>
        <h1>Bonjour <?= e($u['prenom']) ?></h1>
    </div>
    <?php if ($horaire): ?>
        <span class="pastille pastille-info">Horaire du jour : <?= e(Horaires::libelle($horaire)) ?><?= $horaire['periode'] ? ' · ' . e($horaire['periode']) : '' ?></span>
    <?php elseif ($ferie = Calendrier::ferie($jour)): ?>
        <span class="pastille pastille-info">Jour férié : <?= e($ferie) ?></span>
    <?php endif; ?>
</div>

<div class="grille grille-tableau">
    <section class="carte badge" data-zone-badge aria-labelledby="titre-badge">
        <div class="badge-entete">
            <h2 id="titre-badge">Pointage</h2>
            <span data-etat><?= pastille_etat($etat) ?></span>
        </div>
        <p class="horloge" data-horloge><?= e($maintenant->format('H:i:s')) ?></p>

        <div class="badge-actions" data-actions>
            <?php foreach (Pointage::actionsPossibles($etat) as $action): ?>
                <form method="post" action="<?= e(url('api/badger.php')) ?>" data-badge>
                    <?= Csrf::champ() ?>
                    <input type="hidden" name="type" value="<?= e($action) ?>">
                    <button type="submit" class="bouton bouton-badge action-<?= e($action) ?>"><?= e(Pointage::LIBELLES[$action]) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
        <p class="annonce" data-annonce aria-live="polite"></p>

        <dl class="chiffres">
            <div><dt>Travaillé aujourd'hui</dt>
                <dd data-compteur data-secondes="<?= $secondesJour ?>" data-en-cours="<?= $etat === Pointage::PRESENT ? 1 : 0 ?>"><?= e(duree($secondesJour)) ?></dd></div>
            <div><dt>Prévu aujourd'hui</dt><dd><?= e(duree(Horaires::secondesPrevues($jour))) ?></dd></div>
        </dl>

        <h3>Aujourd'hui</h3>
        <ol class="chronologie" data-chronologie>
            <?php foreach ($evenements as $ev): ?>
                <li><time><?= e(heure($ev['horodatage'])) ?></time> <?= e(Pointage::LIBELLES[$ev['type']]) ?></li>
            <?php endforeach; ?>
            <?php if ($evenements === []): ?><li class="vide">Aucun badge pour l'instant.</li><?php endif; ?>
        </ol>
    </section>

    <div class="colonne">
        <section class="carte">
            <h2>Ma semaine</h2>
            <p class="grand-chiffre"><?= e(duree($secondesSemaine)) ?> <small>sur <?= e(duree($prevuSemaine)) ?> prévues</small></p>
            <meter min="0" max="<?= max($prevuSemaine, 1) ?>" low="<?= (int) ($prevuSemaine * 0.8) ?>" high="<?= $prevuSemaine ?>"
                   optimum="<?= $prevuSemaine ?>" value="<?= min($secondesSemaine, $prevuSemaine) ?>"></meter>
            <ul class="semaine">
                <?php foreach (array_slice($semaine, 0, 5) as $j): ?>
                    <li class="<?= $j['jour'] == $jour ? 'aujourdhui' : '' ?>">
                        <span><?= e(mb_substr(NOMS_JOURS[(int) $j['jour']->format('N')], 0, 3)) ?></span>
                        <strong><?= $j['secondes'] ? e(duree($j['secondes'])) : ($j['ferie'] ? 'férié' : '—') ?></strong>
                        <?php if ($j['anomalies']): ?><span class="alerte-point" title="<?= e(implode(', ', $j['anomalies'])) ?>">!</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="lien-fleche" href="<?= e(url('pointages.php')) ?>">Détail de mes pointages</a>
        </section>

        <section class="carte">
            <h2>Mes droits <?= $annee ?></h2>
            <div class="soldes">
                <?php foreach ($soldes as $type => $s): ?>
                    <div class="solde">
                        <span class="solde-type"><?= e(Conges::TYPES[$type]) ?></span>
                        <span class="solde-valeur"><?= e(nombre($s['disponible'])) ?> <small>j</small></span>
                        <span class="solde-detail"><?= e(nombre($s['pris'])) ?> pris · <?= e(nombre($s['en_attente'])) ?> en cours</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($prochain): ?>
                <p class="info">Prochain congé accordé : <?= e(periode_conge($prochain)) ?></p>
            <?php endif; ?>
            <a class="bouton" href="<?= e(url('conges.php')) ?>">Demander un congé</a>
        </section>

        <?php if ($equipe): ?>
        <section class="carte">
            <h2><?= in_array($u['role'], Employes::VISION_GLOBALE, true) ? 'La direction' : 'Mon service' ?> aujourd'hui <small>(<?= $equipe['effectif'] ?> agents)</small></h2>
            <div class="kpis">
                <div><strong><?= $equipe['compte']['present'] ?></strong><span>au travail</span></div>
                <div><strong><?= $equipe['compte']['pause'] ?></strong><span>en pause</span></div>
                <div><strong><?= $equipe['compte']['conge'] ?></strong><span>en congé</span></div>
                <div><strong><?= $equipe['compte']['non_badge'] ?></strong><span>non badgés</span></div>
            </div>
            <?php if ($equipe['a_traiter'] > 0): ?>
                <a class="bouton bouton-principal" href="<?= e(url('validation.php')) ?>"><?= $equipe['a_traiter'] ?> demande(s) à traiter</a>
            <?php else: ?>
                <p class="info">Aucune demande en attente de votre part.</p>
            <?php endif; ?>
            <a class="lien-fleche" href="<?= e(url('equipe.php')) ?>">Voir les présences et absences</a>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php pied();
