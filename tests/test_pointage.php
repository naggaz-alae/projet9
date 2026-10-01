<?php

declare(strict_types=1);

$ev = fn (string $type, string $heure) => ['type' => $type, 'horodatage' => "2026-10-05 $heure:00"];

test('Badgeage : enchaînement autorisé', function () {
    $etat = Pointage::suivant(Pointage::HORS, 'entree');
    $etat = Pointage::suivant($etat, 'debut_pause');
    $etat = Pointage::suivant($etat, 'fin_pause');
    egal(Pointage::HORS, Pointage::suivant($etat, 'sortie'));
    egal(Pointage::HORS, Pointage::suivant(Pointage::PAUSE, 'sortie'), 'départ depuis la pause');
});

test('Badgeage : transitions interdites', function () {
    erreur_metier(fn () => Pointage::suivant(Pointage::PRESENT, 'entree'), 'Arrivée');
    erreur_metier(fn () => Pointage::suivant(Pointage::HORS, 'debut_pause'));
    erreur_metier(fn () => Pointage::suivant(Pointage::HORS, 'sortie'));
    erreur_metier(fn () => Pointage::suivant(Pointage::HORS, 'inconnu'), 'inconnu');
});

test('Temps travaillé : pause exclue', function () use ($ev) {
    $journee = [$ev('entree', '08:30'), $ev('debut_pause', '12:00'), $ev('fin_pause', '12:45'), $ev('sortie', '17:00')];
    egal(7 * 3600 + 45 * 60, Pointage::secondesTravaillees($journee));   // 3h30 + 4h15
});

test('Temps travaillé : journée en cours comptée jusqu\'à maintenant', function () use ($ev) {
    $maintenant = new DateTimeImmutable('2026-10-05 10:00:00');
    egal(5400, Pointage::secondesTravaillees([$ev('entree', '08:30')], $maintenant));
    egal(0, Pointage::secondesTravaillees([$ev('entree', '08:30')]), 'journée passée non refermée');
    // En pause : le temps n'avance plus
    $enPause = [$ev('entree', '08:00'), $ev('debut_pause', '09:00')];
    egal(3600, Pointage::secondesTravaillees($enPause, $maintenant));
});

test('Assiduité : retard, départ anticipé, départ manquant', function () use ($ev) {
    $horaire = ['debut' => '08:30', 'fin' => '16:30'];
    egal([], Pointage::anomalies([$ev('entree', '08:33'), $ev('sortie', '16:30')], true, $horaire, 5), 'dans la tolérance');
    egal(['Retard : arrivée à 08:52'], Pointage::anomalies([$ev('entree', '08:52'), $ev('sortie', '16:40')], true, $horaire, 5));
    egal(['Départ anticipé : 15:30'], Pointage::anomalies([$ev('entree', '08:20'), $ev('sortie', '15:30')], true, $horaire, 5));
    egal(['Badge de départ manquant'], Pointage::anomalies([$ev('entree', '08:30')], true, $horaire, 5));
    egal([], Pointage::anomalies([$ev('entree', '08:30')], false, $horaire, 5), "aujourd'hui : pas encore une anomalie");
    egal([], Pointage::anomalies([], true, $horaire, 5), 'jour sans badge (congé)');
    egal([], Pointage::anomalies([$ev('entree', '10:00'), $ev('sortie', '12:00')], true, null, 5), 'jour non travaillé : pas de retard');
});

test('Événements incohérents ignorés au lieu de bloquer', function () use ($ev) {
    $donnees = [$ev('entree', '08:00'), $ev('entree', '08:05'), $ev('sortie', '12:00')];
    egal(Pointage::HORS, Pointage::etat($donnees));
    egal(4 * 3600, Pointage::secondesTravaillees($donnees));
});

test('Service : badger enregistre et contrôle en base', function () {
    $db = base_de_test();
    $id = creer_employe($db, 'E1');
    $service = new PointageService($db);
    $matin = new DateTimeImmutable('2026-10-05 08:30:00');

    egal(Pointage::PRESENT, $service->badger($id, 'entree', 'web', $matin));
    erreur_metier(fn () => $service->badger($id, 'entree', 'web', $matin->modify('+1 minute')));
    egal(Pointage::HORS, $service->badger($id, 'sortie', 'borne', $matin->modify('+8 hours')));
    egal(2, count($service->evenements($id, $matin)));
    // Le lendemain repart de « non badgé »
    egal(Pointage::PRESENT, $service->badger($id, 'entree', 'web', $matin->modify('+1 day')));
});

test('Service : résumé d\'une période jour par jour', function () {
    $db = base_de_test();
    $id = creer_employe($db, 'E1');
    $service = new PointageService($db);
    $service->badger($id, 'entree', 'web', new DateTimeImmutable('2026-10-05 09:00'));
    $service->badger($id, 'sortie', 'web', new DateTimeImmutable('2026-10-05 17:00'));
    $service->badger($id, 'entree', 'web', new DateTimeImmutable('2026-10-06 09:00'));

    $jours = $service->periode($id, new DateTimeImmutable('2026-10-05'), new DateTimeImmutable('2026-10-11'),
        new DateTimeImmutable('2026-10-08 12:00'));
    egal(7, count($jours));
    egal(8 * 3600, $jours[0]['secondes']);
    egal(['Badge de départ manquant', 'Retard : arrivée à 09:00'], $jours[1]['anomalies']);
    egal(7 * 3600 + 1800, $jours[0]['prevu']);
});
