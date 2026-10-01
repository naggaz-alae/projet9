<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db);
$maintenant = new DateTimeImmutable();
$employes = new Employes($db);

// Quel employé ? (soi-même par défaut ; son équipe pour un manager ; tout le monde pour les RH)
$cible = $u;
$idDemande = param_entier('employe');
if ($idDemande !== null && $idDemande !== (int) $u['id']) {
    $cible = $employes->parId($idDemande);
    if ($cible === null || !Employes::peutConsulter($u, $cible)) {
        flash('erreur', "Vous ne pouvez pas consulter les pointages de cette personne.");
        rediriger('pointages.php');
    }
}

// Quelle semaine ? (champ <input type="week"> : « 2026-W40 »)
$lundi = lundi_de($maintenant);
if (preg_match('/^(\d{4})-W(\d{2})$/', param('semaine'), $m)) {
    $lundi = (new DateTimeImmutable())->setISODate((int) $m[1], (int) $m[2])->setTime(0, 0);
}
$dimanche = $lundi->modify('+6 days');

$jours = (new PointageService($db))->periode((int) $cible['id'], $lundi, $dimanche, $maintenant);
$total = array_sum(array_column($jours, 'secondes'));

// Congés accordés de la semaine : ces jours-là, rien n'est attendu
$congesSemaine = [];
foreach ((new Conges($db))->absences([(int) $cible['id']], $lundi->format('Y-m-d'), $dimanche->format('Y-m-d')) as $c) {
    for ($d = new DateTimeImmutable($c['date_debut']); $d <= new DateTimeImmutable($c['date_fin']); $d = $d->modify('+1 day')) {
        $congesSemaine[$d->format('Y-m-d')] = Conges::TYPES[$c['type']];
    }
}
// Objectif = somme des horaires officiels des jours travaillés hors congés
$objectif = 0;
$retards = 0;
foreach ($jours as $j) {
    if (!isset($congesSemaine[$j['jour']->format('Y-m-d')])) {
        $objectif += $j['prevu'];
    }
    foreach ($j['anomalies'] as $a) {
        $retards += str_starts_with($a, 'Retard') ? 1 : 0;
    }
}
$ecart = $total - $objectif;

$lienSemaine = fn (DateTimeImmutable $d) => url('pointages.php?' . http_build_query(array_filter([
    'semaine' => $d->format('o-\WW'),
    'employe' => (int) $cible['id'] !== (int) $u['id'] ? $cible['id'] : null,
])));

entete('Pointages', $u, 'pointages.php');
?>
<div class="titre-page">
    <div>
        <p class="surtitre"><?= (int) $cible['id'] === (int) $u['id'] ? 'Mes pointages' : 'Pointages de ' . e(Employes::nomComplet($cible)) ?></p>
        <h1>Semaine du <?= e($lundi->format('d/m')) ?> au <?= e($dimanche->format('d/m/Y')) ?></h1>
    </div>
    <nav class="pagination" aria-label="Changer de semaine">
        <a class="bouton" href="<?= e($lienSemaine($lundi->modify('-7 days'))) ?>">← Précédente</a>
        <form method="get" class="en-ligne">
            <?php if ((int) $cible['id'] !== (int) $u['id']): ?><input type="hidden" name="employe" value="<?= (int) $cible['id'] ?>"><?php endif; ?>
            <label class="visuellement-cache" for="semaine">Semaine</label>
            <input type="week" id="semaine" name="semaine" value="<?= e($lundi->format('o-\WW')) ?>" data-soumettre-au-changement>
        </form>
        <a class="bouton" href="<?= e($lienSemaine($lundi->modify('+7 days'))) ?>">Suivante →</a>
    </nav>
</div>

<div class="kpis kpis-bandeau">
    <div><strong><?= e(duree($total)) ?></strong><span>travaillé</span></div>
    <div><strong><?= e(duree($objectif)) ?></strong><span>prévu selon l'horaire officiel</span></div>
    <div class="<?= $ecart >= 0 ? 'positif' : 'negatif' ?>"><strong><?= e(($ecart >= 0 ? '+' : '') . duree($ecart)) ?></strong><span>écart</span></div>
    <div class="<?= $retards ? 'negatif' : 'positif' ?>"><strong><?= $retards ?></strong><span>retard(s)</span></div>
</div>

<section class="carte">
    <div class="tableau-defilant">
    <table class="tableau">
        <thead><tr><th>Jour</th><th>Horaire</th><th>Badges</th><th class="nombre">Travaillé</th><th>Remarques</th></tr></thead>
        <tbody>
        <?php foreach ($jours as $j):
            $cle = $j['jour']->format('Y-m-d');
            $weekend = (int) $j['jour']->format('N') >= 6; ?>
            <tr class="<?= $weekend ? 'attenue' : '' ?>">
                <th scope="row"><?= e(ucfirst(NOMS_JOURS[(int) $j['jour']->format('N')])) ?> <?= e($j['jour']->format('d/m')) ?></th>
                <td class="petit"><?php $h = Horaires::du($j['jour']); echo $h ? e(Horaires::libelle($h)) : ''; ?></td>
                <td>
                    <?php foreach ($j['evenements'] as $ev): ?>
                        <span class="puce-badge action-<?= e($ev['type']) ?>" title="<?= e(Pointage::LIBELLES[$ev['type']]) ?> (<?= e($ev['source']) ?>)">
                            <?= e(heure($ev['horodatage'])) ?> <?= e(Pointage::LIBELLES[$ev['type']]) ?></span>
                    <?php endforeach; ?>
                </td>
                <td class="nombre"><?= $j['secondes'] ? e(duree($j['secondes'])) : '' ?></td>
                <td>
                    <?php if ($j['ferie']): ?><span class="pastille pastille-info"><?= e($j['ferie']) ?></span><?php endif; ?>
                    <?php if (isset($congesSemaine[$cle])): ?><span class="pastille pastille-approuvee"><?= e($congesSemaine[$cle]) ?></span><?php endif; ?>
                    <?php foreach ($j['anomalies'] as $a): ?><span class="pastille pastille-refusee"><?= e($a) ?></span><?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php if ($u['role'] !== 'fonctionnaire'): ?>
<section class="carte">
    <h2>Consulter un autre membre</h2>
    <form method="get" class="en-ligne">
        <input type="hidden" name="semaine" value="<?= e($lundi->format('o-\WW')) ?>">
        <label for="employe">Agent</label>
        <select id="employe" name="employe" data-soumettre-au-changement>
            <?php foreach ($employes->perimetre($u) as $e): ?>
                <option value="<?= (int) $e['id'] ?>"<?= (int) $e['id'] === (int) $cible['id'] ? ' selected' : '' ?>><?= e(Employes::nomComplet($e)) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="bouton" type="submit">Afficher</button></noscript>
    </form>
</section>
<?php endif; ?>
<?php pied();
