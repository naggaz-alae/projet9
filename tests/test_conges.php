<?php

declare(strict_types=1);

/** Direction type : directeur, bureau du personnel, 2 chefs de service, 2 fonctionnaires (un par service). */
function direction_de_test(): array
{
    $db = base_de_test();
    $dir = creer_employe($db, 'DIR', 'directeur', null);
    $rh = creer_employe($db, 'PER', 'personnel', $dir);
    $chefA = creer_employe($db, 'CA', 'chef_service', $dir);
    $chefB = creer_employe($db, 'CB', 'chef_service', $dir);
    $youssef = creer_employe($db, 'YOU', 'fonctionnaire', $chefA);
    $nadia = creer_employe($db, 'NAD', 'fonctionnaire', $chefB);
    return [$db, new Conges($db), compact('dir', 'rh', 'chefA', 'chefB', 'youssef', 'nadia')];
}

$maintenant = new DateTimeImmutable('2026-10-01 09:00');

test('Droits annuels : 22 jours administratifs et 10 exceptionnels par défaut', function () {
    [, $conges, $p] = direction_de_test();
    $soldes = $conges->soldes($p['youssef'], 2026);
    egal(22.0, $soldes['administratif']['disponible']);
    egal(10.0, $soldes['exceptionnel']['disponible']);
});

test('Dépôt : jours décomptés, droits réservés, transmis au chef pour avis', function () use ($maintenant) {
    [, $conges, $p] = direction_de_test();
    $id = $conges->demander($p['youssef'], 'administratif', '2026-10-12', '2026-10-16', false, false, '', $maintenant);
    egal('en_attente_avis', $conges->demande($id)['statut']);
    $solde = $conges->soldes($p['youssef'], 2026)['administratif'];
    egal(5.0, $solde['en_attente']);
    egal(17.0, $solde['disponible']);
});

test('Dépôt par un chef de service : directement en attente de décision', function () use ($maintenant) {
    [, $conges, $p] = direction_de_test();
    $id = $conges->demander($p['chefA'], 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant);
    egal('en_attente_decision', $conges->demande($id)['statut']);
});

test('Circuit complet : avis favorable du chef, puis accord du directeur', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    $id = $conges->demander($p['youssef'], 'administratif', '2026-10-12', '2026-10-13', false, false, '', $maintenant);
    erreur_metier(fn () => $conges->decider($id, employe($db, $p['dir']), true, '', $maintenant), "l'avis du chef");
    $conges->donnerAvis($id, employe($db, $p['chefA']), true, '', $maintenant);
    egal('en_attente_decision', $conges->demande($id)['statut']);
    egal('favorable', $conges->demande($id)['avis']);
    $conges->decider($id, employe($db, $p['dir']), true, '', $maintenant);
    egal('approuvee', $conges->demande($id)['statut']);
    egal(2.0, $conges->soldes($p['youssef'], 2026)['administratif']['pris']);
});

test('Avis défavorable : motivé, et le directeur décide quand même', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    $id = $conges->demander($p['youssef'], 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant);
    erreur_metier(fn () => $conges->donnerAvis($id, employe($db, $p['chefA']), false, ' ', $maintenant), 'motivé');
    $conges->donnerAvis($id, employe($db, $p['chefA']), false, 'Réception de travaux ce jour-là', $maintenant);
    erreur_metier(fn () => $conges->decider($id, employe($db, $p['dir']), false, '', $maintenant), 'motivé');
    $conges->decider($id, employe($db, $p['dir']), false, 'Merci de proposer une autre date', $maintenant);
    egal('refusee', $conges->demande($id)['statut']);
    egal(22.0, $conges->soldes($p['youssef'], 2026)['administratif']['disponible'], 'un refus libère les droits');
});

test('Habilitations : chef d\'un autre service, fonctionnaire, bureau du personnel', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    $id = $conges->demander($p['youssef'], 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant);
    erreur_metier(fn () => $conges->donnerAvis($id, employe($db, $p['chefB']), true, '', $maintenant), 'habilité');
    erreur_metier(fn () => $conges->donnerAvis($id, employe($db, $p['nadia']), true, '', $maintenant), 'habilité');
    $conges->donnerAvis($id, employe($db, $p['chefA']), true, '', $maintenant);
    erreur_metier(fn () => $conges->decider($id, employe($db, $p['chefA']), true, '', $maintenant), 'habilité');
    erreur_metier(fn () => $conges->decider($id, employe($db, $p['rh']), true, '', $maintenant), 'habilité');
});

test('Congé du directeur : enregistré par le bureau du personnel, jamais par lui-même', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    $id = $conges->demander($p['dir'], 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant);
    erreur_metier(fn () => $conges->decider($id, employe($db, $p['dir']), true, '', $maintenant), 'habilité');
    $conges->decider($id, employe($db, $p['rh']), true, '', $maintenant);
    egal('approuvee', $conges->demande($id)['statut']);
});

test('Règles de dépôt : droits insuffisants, chevauchement, passé, deux années', function () use ($maintenant) {
    [, $conges, $p] = direction_de_test();
    erreur_metier(fn () => $conges->demander($p['youssef'], 'administratif', '2026-10-01', '2026-11-30', false, false, '', $maintenant), 'Solde insuffisant');
    $conges->demander($p['youssef'], 'administratif', '2026-10-12', '2026-10-13', false, false, '', $maintenant);
    erreur_metier(fn () => $conges->demander($p['youssef'], 'administratif', '2026-10-13', '2026-10-16', false, false, '', $maintenant), 'chevauche');
    erreur_metier(fn () => $conges->demander($p['youssef'], 'administratif', '2026-09-28', '2026-09-29', false, false, '', $maintenant), 'passé');
    erreur_metier(fn () => $conges->demander($p['youssef'], 'administratif', '2026-12-28', '2027-01-04', false, false, '', $maintenant), 'deux années');
    erreur_metier(fn () => $conges->demander($p['youssef'], 'administratif', '2026-10-10', '2026-10-11', false, false, '', $maintenant), 'aucun jour');
});

test('Congé exceptionnel : motif obligatoire, plafond de 10 jours', function () use ($maintenant) {
    [, $conges, $p] = direction_de_test();
    erreur_metier(fn () => $conges->demander($p['youssef'], 'exceptionnel', '2026-10-12', '2026-10-12', false, false, '', $maintenant), 'événement familial');
    erreur_metier(fn () => $conges->demander($p['youssef'], 'exceptionnel', '2026-10-12', '2026-10-27', false, false, 'Décès', $maintenant), 'Solde insuffisant');
    vrai($conges->demander($p['youssef'], 'exceptionnel', '2026-10-12', '2026-10-13', false, false, 'Mariage', $maintenant) > 0);
});

test('Premier congé administratif après douze mois de service', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    $recrue = creer_employe($db, 'NEW', 'fonctionnaire', $p['chefA'], '2026-03-01');
    erreur_metier(fn () => $conges->demander($recrue, 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant), '01/03/2027');
    // Le congé exceptionnel reste possible
    vrai($conges->demander($recrue, 'exceptionnel', '2026-10-12', '2026-10-12', false, false, 'Naissance', $maintenant) > 0);
});

test('Droits ajustés par le bureau du personnel (report exceptionnel)', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    (new Employes($db))->definirDroits($p['youssef'], 'administratif', 2026, 30);
    egal(30.0, $conges->soldes($p['youssef'], 2026)['administratif']['acquis']);
    egal(22.0, $conges->soldes($p['youssef'], 2027)['administratif']['acquis'], "l'année suivante reprend la valeur par défaut");
});

test('Annulation : sa propre demande, tant qu\'elle est en cours', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    $id = $conges->demander($p['youssef'], 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant);
    erreur_metier(fn () => $conges->annuler($id, $p['nadia'], $maintenant), 'introuvable');
    $conges->donnerAvis($id, employe($db, $p['chefA']), true, '', $maintenant);
    $conges->annuler($id, $p['youssef'], $maintenant);   // encore possible en attente de décision
    egal('annulee', $conges->demande($id)['statut']);
    erreur_metier(fn () => $conges->annuler($id, $p['youssef'], $maintenant), 'en cours de validation');
});

test('File de travail de chacun', function () use ($maintenant) {
    [$db, $conges, $p] = direction_de_test();
    $conges->demander($p['youssef'], 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant);
    $conges->demander($p['chefB'], 'administratif', '2026-10-12', '2026-10-12', false, false, '', $maintenant);
    $conges->demander($p['dir'], 'administratif', '2026-10-14', '2026-10-14', false, false, '', $maintenant);
    egal(1, count($conges->aTraiter(employe($db, $p['chefA']))), 'chef A : un avis');
    egal(0, count($conges->aTraiter(employe($db, $p['chefB']))), 'chef B : rien (sa propre demande)');
    egal(1, count($conges->aTraiter(employe($db, $p['dir']))), 'directeur : la demande du chef B');
    egal(1, count($conges->aTraiter(employe($db, $p['rh']))), 'personnel : la demande du directeur');
    egal(0, count($conges->aTraiter(employe($db, $p['nadia']))));
});
