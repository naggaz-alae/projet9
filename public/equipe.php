<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db);
$maintenant = new DateTimeImmutable();
$estResponsable = $u['role'] !== 'fonctionnaire';

$membres = (new Employes($db))->perimetre($u);

// Mois affiché (?mois=2026-10)
$mois = DateTimeImmutable::createFromFormat('!Y-m', param('mois')) ?: $maintenant->modify('first day of this month')->setTime(0, 0);
$debutMois = $mois->modify('first day of this month');
$finMois = $mois->modify('last day of this month');

$conges = new Conges($db);
$absences = $conges->absences(array_map(fn (array $e) => (int) $e['id'], $membres),
    $debutMois->format('Y-m-d'), $finMois->format('Y-m-d'), $estResponsable);

// Grille [employé][jour] => demande
$grille = [];
foreach ($absences as $a) {
    for ($d = new DateTimeImmutable($a['date_debut']); $d <= new DateTimeImmutable($a['date_fin']); $d = $d->modify('+1 day')) {
        $grille[(int) $a['employe_id']][$d->format('Y-m-d')] = $a;
    }
}
$jours = [];
for ($d = $debutMois; $d <= $finMois; $d = $d->modify('+1 day')) {
    $jours[] = $d;
}

$situation = $estResponsable ? (new PointageService($db))->situationDuJour($membres, $maintenant) : [];
$aujourdhui = aujourdhui($maintenant)->format('Y-m-d');

entete('Équipe', $u, 'equipe.php');
?>
<div class="titre-page">
    <h1>Présences et absences</h1>
</div>

<?php if ($estResponsable): ?>
<section class="carte">
    <h2>Qui est là ? <small>situation à <?= e($maintenant->format('H:i')) ?></small></h2>
    <div class="tableau-defilant">
    <table class="tableau">
        <thead><tr><th>Agent</th><th>Service</th><th>Statut</th><th>Arrivée</th><th class="nombre">Travaillé</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($situation as $s):
            $id = (int) $s['employe']['id'];
            $enConge = $grille[$id][$aujourdhui] ?? null;
            $enConge = ($enConge && $enConge['statut'] === 'approuvee') ? $enConge : null; ?>
            <tr>
                <th scope="row"><?= e(Employes::nomComplet($s['employe'])) ?></th>
                <td><?= e($s['employe']['service'] ?? '') ?></td>
                <td><?= $enConge && $s['etat'] === Pointage::HORS
                        ? '<span class="pastille pastille-approuvee">En congé</span>'
                        : pastille_etat($s['etat']) ?></td>
                <td><?= e($s['arrivee'] ?? '—') ?></td>
                <td class="nombre"><?= $s['secondes'] ? e(duree($s['secondes'])) : '' ?></td>
                <td><a href="<?= e(url('pointages.php?employe=' . $id)) ?>">Pointages</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>

<section class="carte">
    <div class="titre-carte">
        <h2>Absences de <?= e(NOMS_MOIS[(int) $debutMois->format('n')] . ' ' . $debutMois->format('Y')) ?></h2>
        <nav class="pagination" aria-label="Changer de mois">
            <a class="bouton" href="<?= e(url('equipe.php?mois=' . $debutMois->modify('-1 month')->format('Y-m'))) ?>">←</a>
            <a class="bouton" href="<?= e(url('equipe.php')) ?>">Ce mois-ci</a>
            <a class="bouton" href="<?= e(url('equipe.php?mois=' . $debutMois->modify('+1 month')->format('Y-m'))) ?>">→</a>
        </nav>
    </div>
    <div class="tableau-defilant">
    <table class="calendrier">
        <thead>
        <tr>
            <th scope="col" class="calendrier-nom">Agent</th>
            <?php foreach ($jours as $d):
                $classes = [];
                if ((int) $d->format('N') >= 6) $classes[] = 'weekend';
                if (Calendrier::ferie($d)) $classes[] = 'ferie';
                if ($d->format('Y-m-d') === $aujourdhui) $classes[] = 'jour-courant'; ?>
                <th scope="col" class="<?= implode(' ', $classes) ?>" title="<?= e(Calendrier::ferie($d) ?? '') ?>">
                    <span><?= e(mb_substr(NOMS_JOURS[(int) $d->format('N')], 0, 1)) ?></span><?= e($d->format('j')) ?></th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($membres as $m): ?>
            <tr>
                <th scope="row" class="calendrier-nom"><?= e(Employes::nomComplet($m)) ?></th>
                <?php foreach ($jours as $d):
                    $cle = $d->format('Y-m-d');
                    $absence = $grille[(int) $m['id']][$cle] ?? null;
                    $classes = [];
                    if ((int) $d->format('N') >= 6) $classes[] = 'weekend';
                    if (Calendrier::ferie($d)) $classes[] = 'ferie';
                    if ($cle === $aujourdhui) $classes[] = 'jour-courant';
                    $titre = '';
                    if ($absence && Calendrier::estOuvre($d)) {
                        $classes[] = $absence['statut'] === 'approuvee' ? 'absent' : 'absent-attente';
                        // Les collègues voient l'absence, pas son type (confidentialité)
                        $titre = ($estResponsable ? Conges::TYPES[$absence['type']] : 'Absent') . ($absence['statut'] !== 'approuvee' ? ' (en cours de validation)' : '');
                        $demi = ($cle === $absence['date_debut'] && (int) $absence['debut_apres_midi']) || ($cle === $absence['date_fin'] && (int) $absence['fin_midi']);
                        if ($demi) $classes[] = 'demi';
                    } ?>
                    <td class="<?= implode(' ', $classes) ?>"<?= $titre ? ' title="' . e($titre) . '"' : '' ?>><?= $titre ? '<span class="visuellement-cache">' . e($titre) . '</span>' : '' ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <ul class="legende">
        <li><span class="echantillon absent"></span> Absent</li>
        <?php if ($estResponsable): ?><li><span class="echantillon absent-attente"></span> Demande en cours de validation</li><?php endif; ?>
        <li><span class="echantillon demi"></span> Demi-journée</li>
        <li><span class="echantillon ferie"></span> Férié</li>
        <li><span class="echantillon weekend"></span> Week-end</li>
    </ul>
</section>
<?php pied();
