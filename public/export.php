<?php

declare(strict_types=1);

/*
 * Export CSV des pointages (une ligne par employé et par jour) et des congés,
 * pour la paie ou l'analyse (Excel, Power BI, Python…).
 */

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db, 'chef_service', 'directeur', 'personnel');
$maintenant = new DateTimeImmutable();

$du = date_valide(param('du')) ?? $maintenant->modify('first day of this month')->setTime(0, 0);
$au = date_valide(param('au')) ?? aujourdhui($maintenant);
$type = param('type');
$format = param('format', 'excel');

if ($type !== '') {
    if ($au < $du || $du->diff($au)->days > 366) {
        flash('erreur', 'Période invalide (un an maximum).');
        rediriger('export.php');
    }
    $membres = (new Employes($db))->perimetre($u);
    $excel = $format === 'excel';
    $separateur = $excel ? ';' : ',';
    $decimal = fn (float $v) => $excel ? str_replace('.', ',', (string) $v) : (string) $v;

    $nomFichier = sprintf('%s_%s_%s.csv', $type === 'conges' ? 'conges' : 'pointages', $du->format('Ymd'), $au->format('Ymd'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    $sortie = fopen('php://output', 'w');
    if ($excel) {
        fwrite($sortie, "\xEF\xBB\xBF");   // BOM : Excel reconnaît l'UTF-8 et les accents
    }
    $ligne = fn (array $valeurs) => fputcsv($sortie, $valeurs, $separateur, '"', '');

    if ($type === 'conges') {
        $ligne(['matricule', 'nom', 'prenom', 'service', 'type', 'date_debut', 'date_fin', 'debut_apres_midi', 'fin_midi', 'nb_jours', 'statut', 'avis_chef', 'cree_le', 'avis_le', 'traitee_le']);
        $requete = (new Conges($db))->absences(array_map(fn (array $e) => (int) $e['id'], $membres), $du->format('Y-m-d'), $au->format('Y-m-d'), true);
        foreach ($requete as $c) {
            $ligne([$c['matricule'], $c['nom'], $c['prenom'], $c['service'], $c['type'], $c['date_debut'], $c['date_fin'],
                (int) $c['debut_apres_midi'], (int) $c['fin_midi'], $decimal((float) $c['nb_jours']), $c['statut'], $c['avis'], $c['cree_le'], $c['avis_le'], $c['traitee_le']]);
        }
    } else {
        $ligne(['matricule', 'nom', 'prenom', 'service', 'date', 'jour_ouvre', 'horaire_debut', 'premiere_arrivee', 'dernier_depart', 'nb_badges', 'heures_travaillees', 'heures_prevues', 'anomalies']);
        $service = new PointageService($db);
        foreach ($membres as $m) {
            foreach ($service->periode((int) $m['id'], $du, $au, $maintenant) as $j) {
                if ($j['evenements'] === []) {
                    continue;
                }
                $arrivees = array_filter($j['evenements'], fn (array $e) => $e['type'] === 'entree');
                $departs = array_filter($j['evenements'], fn (array $e) => $e['type'] === 'sortie');
                $horaire = Horaires::du($j['jour']);
                $ligne([$m['matricule'], $m['nom'], $m['prenom'], $m['service'], $j['jour']->format('Y-m-d'),
                    Calendrier::estOuvre($j['jour']) ? 1 : 0, $horaire['debut'] ?? '',
                    $arrivees ? heure(reset($arrivees)['horodatage']) : '',
                    $departs ? heure(end($departs)['horodatage']) : '',
                    count($j['evenements']), $decimal(round($j['secondes'] / 3600, 2)),
                    $decimal(round($j['prevu'] / 3600, 2)), implode(' | ', $j['anomalies'])]);
            }
        }
    }
    fclose($sortie);
    exit;
}

entete('Export', $u, 'export.php');
?>
<div class="titre-page"><h1>Export CSV</h1></div>

<section class="carte carte-etroite">
    <form method="get" class="formulaire">
        <label>Données
            <select name="type">
                <option value="pointages">Pointages (une ligne par agent et par jour)</option>
                <option value="conges">Congés (accordés et en cours de validation)</option>
            </select>
        </label>
        <div class="ligne-champs">
            <label>Du <input type="date" name="du" value="<?= e($du->format('Y-m-d')) ?>" required></label>
            <label>Au <input type="date" name="au" value="<?= e($au->format('Y-m-d')) ?>" required></label>
        </div>
        <fieldset>
            <legend>Format</legend>
            <label class="case"><input type="radio" name="format" value="excel" checked> Excel (francophone) : séparateur « ; », virgule décimale</label>
            <label class="case"><input type="radio" name="format" value="standard"> Standard : séparateur « , », point décimal (Python, Power BI…)</label>
        </fieldset>
        <button type="submit" class="bouton bouton-principal">Télécharger</button>
        <p class="aide">Périmètre : <?= $u['role'] === 'chef_service' ? 'vous et votre service' : 'toute la direction' ?>.</p>
    </form>
</section>
<?php pied();
