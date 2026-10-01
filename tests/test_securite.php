<?php

declare(strict_types=1);

$maintenant = new DateTimeImmutable('2026-10-01 09:00');

test('Connexion : bons identifiants acceptés, mauvais refusés', function () use ($maintenant) {
    $db = base_de_test();
    creer_employe($db, 'E1');
    egal('E1', Auth::verifier($db, ' E1@Test.ma ', 'motdepasse', $maintenant)['matricule']);
    erreur_metier(fn () => Auth::verifier($db, 'e1@test.ma', 'mauvais', $maintenant), 'Identifiants incorrects');
    erreur_metier(fn () => Auth::verifier($db, 'inconnu@test.ma', 'motdepasse', $maintenant), 'Identifiants incorrects');
});

test('Connexion : blocage après 5 échecs, levé après 15 minutes', function () use ($maintenant) {
    $db = base_de_test();
    creer_employe($db, 'E1');
    for ($i = 0; $i < 5; $i++) {
        erreur_metier(fn () => Auth::verifier($db, 'e1@test.ma', 'mauvais', $maintenant));
    }
    // Même le bon mot de passe est refusé pendant le blocage
    erreur_metier(fn () => Auth::verifier($db, 'e1@test.ma', 'motdepasse', $maintenant), 'Trop de tentatives');
    egal('E1', Auth::verifier($db, 'e1@test.ma', 'motdepasse', $maintenant->modify('+16 minutes'))['matricule']);
});

test('Compte désactivé : connexion impossible', function () use ($maintenant) {
    $db = base_de_test();
    $id = creer_employe($db, 'E1');
    $db->exec("UPDATE employes SET actif = 0 WHERE id = $id");
    erreur_metier(fn () => Auth::verifier($db, 'e1@test.ma', 'motdepasse', $maintenant));
});

test('Borne : matricule + PIN, avec blocage', function () use ($maintenant) {
    $db = base_de_test();
    creer_employe($db, 'E1', pin: '4321');
    egal('E1', Auth::verifierPin($db, 'e1', '4321', $maintenant)['matricule']);
    for ($i = 0; $i < 5; $i++) {
        erreur_metier(fn () => Auth::verifierPin($db, 'E1', '0000', $maintenant), 'incorrect');
    }
    erreur_metier(fn () => Auth::verifierPin($db, 'E1', '4321', $maintenant), 'Trop de tentatives');
});

test('Mots de passe et PIN jamais stockés en clair', function () {
    $db = base_de_test();
    $id = creer_employe($db, 'E1', pin: '4321');
    $ligne = employe($db, $id);
    vrai(str_starts_with($ligne['mot_de_passe'], '$2y$'), 'mot de passe haché (bcrypt)');
    vrai(str_starts_with($ligne['pin'], '$2y$'), 'PIN haché (bcrypt)');
});

test('Échappement HTML (protection XSS)', function () {
    egal('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
    egal('l&#039;équipe', e("l'équipe"));
});

test('Visibilité des pointages selon le rôle', function () {
    $dir = ['id' => 1, 'role' => 'directeur', 'manager_id' => null];
    $rh = ['id' => 2, 'role' => 'personnel', 'manager_id' => 1];
    $chef = ['id' => 3, 'role' => 'chef_service', 'manager_id' => 1];
    $agent = ['id' => 4, 'role' => 'fonctionnaire', 'manager_id' => 3];
    $autre = ['id' => 5, 'role' => 'fonctionnaire', 'manager_id' => 9];
    vrai(Employes::peutConsulter($agent, $agent), 'soi-même');
    vrai(Employes::peutConsulter($chef, $agent), 'son chef de service');
    vrai(Employes::peutConsulter($dir, $autre), 'le directeur');
    vrai(Employes::peutConsulter($rh, $autre), 'le bureau du personnel');
    vrai(!Employes::peutConsulter($chef, $autre), "chef d'un autre service");
    vrai(!Employes::peutConsulter($agent, $chef), 'un fonctionnaire ne voit pas son chef');
});

test('Fiche employé : validations et doublons', function () {
    $db = base_de_test();
    $employes = new Employes($db);
    $maintenant = new DateTimeImmutable();
    $base = ['matricule' => 'f10', 'prenom' => 'Amina', 'nom' => 'Kettani', 'email' => 'amina@test.ma', 'role' => 'fonctionnaire',
        'date_entree' => '2021-03-01', 'mot_de_passe' => 'unmotdepasse', 'pin' => '1234',
        'droits_administratif' => '24', 'droits_exceptionnel' => '10'];
    $id = $employes->enregistrer($base, null, $maintenant);
    egal('F10', $employes->parId($id)['matricule'], 'matricule mis en majuscules');
    egal(24.0, (new Conges($db))->droits($id, 'administratif', (int) $maintenant->format('Y')), 'droits saisis enregistrés');
    erreur_metier(fn () => $employes->enregistrer($base, null, $maintenant), 'déjà utilisé');
    erreur_metier(fn () => $employes->enregistrer([...$base, 'matricule' => 'X2', 'email' => 'pas-un-email'], null, $maintenant), 'e-mail');
    erreur_metier(fn () => $employes->enregistrer([...$base, 'matricule' => 'X3', 'email' => 'x3@test.ma', 'pin' => '12'], null, $maintenant), 'PIN');
    erreur_metier(fn () => $employes->enregistrer([...$base, 'matricule' => 'X4', 'email' => 'x4@test.ma', 'mot_de_passe' => 'court'], null, $maintenant), '8 caractères');
    erreur_metier(fn () => $employes->enregistrer([...$base, 'matricule' => 'X5', 'email' => 'x5@test.ma', 'role' => 'stagiaire'], null, $maintenant), 'Rôle');
});

test('Données de démonstration : chargement cohérent', function () {
    $db = base_de_test();
    Installateur::chargerDemo($db, new DateTimeImmutable('2026-10-01 11:00'), 4);
    egal(10, (int) $db->query('SELECT COUNT(*) FROM employes')->fetchColumn());
    vrai((int) $db->query('SELECT COUNT(*) FROM pointages')->fetchColumn() > 300, 'pointages générés');
    egal(1, (int) $db->query("SELECT COUNT(*) FROM demandes_conges WHERE statut = 'en_attente_avis'")->fetchColumn());
    egal(4, (int) $db->query("SELECT COUNT(*) FROM demandes_conges WHERE statut = 'en_attente_decision'")->fetchColumn());
    egal(7, (int) $db->query('SELECT COUNT(*) FROM feries_variables')->fetchColumn(), 'fêtes religieuses 2026');
});
