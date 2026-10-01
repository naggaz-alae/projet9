<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/** Création du schéma et jeu de données de démonstration (direction et agents fictifs). */
final class Installateur
{
    private const TABLES = ['tentatives_connexion', 'horaires_speciaux', 'feries_variables', 'demandes_conges',
        'soldes', 'pointages', 'employes'];

    public static function creerSchema(PDO $db): void
    {
        $pilote = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $fichier = RACINE . "/database/schema.$pilote.sql";
        if (!is_file($fichier)) {
            throw new RuntimeException("Base de données non prise en charge : $pilote (sqlite ou mysql).");
        }
        $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($fichier));
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $requete) {
            $db->exec($requete);
        }
    }

    public static function supprimerTables(PDO $db): void
    {
        foreach (self::TABLES as $table) {
            $db->exec("DROP TABLE IF EXISTS $table");
        }
    }

    /**
     * Données de démonstration datées par rapport à $maintenant : trois semaines de pointages,
     * des congés à chaque étape du circuit (avis, décision, accordé, refusé), des retards à détecter.
     */
    public static function chargerDemo(PDO $db, DateTimeImmutable $maintenant, int $coutHash = 10): void
    {
        mt_srand(2021);
        $hash = fn (string $secret) => password_hash($secret, PASSWORD_BCRYPT, ['cost' => $coutHash]);
        $motDePasse = $hash('demo1234');
        $cree = $maintenant->format('Y-m-d H:i:s');
        $jour = aujourdhui($maintenant);
        $annee = (int) $jour->format('Y');

        // ---- Calendrier de l'année : fêtes religieuses et horaire du ramadan (dates indicatives) ----
        if ($annee === 2026) {
            $feries = [
                '2026-03-20' => 'Aïd al-Fitr (date indicative)',
                '2026-03-21' => 'Aïd al-Fitr (date indicative)',
                '2026-05-27' => 'Aïd al-Adha (date indicative)',
                '2026-05-28' => 'Aïd al-Adha (date indicative)',
                '2026-06-16' => '1er Moharram (date indicative)',
                '2026-08-25' => 'Aïd al-Mawlid (date indicative)',
                '2026-08-26' => 'Aïd al-Mawlid (date indicative)',
            ];
            $insertFerie = $db->prepare('INSERT INTO feries_variables (date_ferie, nom) VALUES (?, ?)');
            foreach ($feries as $date => $nom) {
                $insertFerie->execute([$date, $nom]);
            }
            $db->prepare('INSERT INTO horaires_speciaux (nom, date_debut, date_fin, heure_debut, heure_fin, pause_minutes)
                          VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(['Ramadan 1447 (horaire indicatif)', '2026-02-19', '2026-03-19', '09:00', '15:00', 0]);
            Calendrier::definirFeriesVariables($feries);
        }

        // ---- Agents (personnes fictives) ----
        $personnes = [
            // clé, matricule, prénom, nom, e-mail, rôle, supérieur, service, PIN, entrée en fonction
            ['dir', 'D001', 'Abdelkader', 'Lahlou', 'directeur@demo.test', 'directeur', null, 'Direction', '1111', '2012-09-01'],
            ['rh', 'P001', 'Fatima Zahra', 'Idrissi', 'personnel@demo.test', 'personnel', 'dir', 'Bureau du personnel', '2222', '2015-03-01'],
            ['karim', 'C001', 'Karim', 'Bennani', 'chef@demo.test', 'chef_service', 'dir', 'Service des routes', '3333', '2014-01-15'],
            ['samira', 'C002', 'Samira', 'Ouazzani', 'samira.ouazzani@demo.test', 'chef_service', 'dir', 'Service du transport', '4444', '2016-06-01'],
            ['youssef', 'F001', 'Youssef', 'Amrani', 'fonctionnaire@demo.test', 'fonctionnaire', 'karim', 'Service des routes', '1234', '2018-10-01'],
            ['hind', 'F002', 'Hind', 'Tazi', 'hind.tazi@demo.test', 'fonctionnaire', 'karim', 'Service des routes', '2345', '2019-02-01'],
            ['omar', 'F003', 'Omar', 'Berrada', 'omar.berrada@demo.test', 'fonctionnaire', 'karim', 'Service des routes', '3456', '2017-05-15'],
            ['salma', 'F004', 'Salma', 'Chraibi', 'salma.chraibi@demo.test', 'fonctionnaire', 'karim', 'Service des routes', '4567', null],
            ['mehdi', 'F005', 'Mehdi', 'Alami', 'mehdi.alami@demo.test', 'fonctionnaire', 'samira', 'Service du transport', '5678', '2020-01-02'],
            ['nadia', 'F006', 'Nadia', 'Fassi', 'nadia.fassi@demo.test', 'fonctionnaire', 'samira', 'Service du transport', '6789', '2016-09-15'],
        ];
        $ids = [];
        $insert = $db->prepare('INSERT INTO employes (matricule, prenom, nom, email, mot_de_passe, pin, role,
            manager_id, service, date_entree, actif, cree_le) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
        foreach ($personnes as [$cle, $matricule, $prenom, $nom, $email, $role, $chef, $service, $pin, $entree]) {
            if ($cle === 'salma') {
                $entree = $jour->modify('-8 months')->format('Y-m-d');   // recrutée récemment : pas encore de congé administratif
            }
            $insert->execute([$matricule, $prenom, $nom, $email, $motDePasse, $hash($pin), $role,
                $chef !== null ? $ids[$chef] : null, $service, $entree, $cree]);
            $ids[$cle] = (int) $db->lastInsertId();
        }

        // ---- Congés à toutes les étapes du circuit ----
        $j = fn (int $n) => Calendrier::decalerJoursOuvres($jour, $n);
        $conges = [
            // agent, type, début, fin, statut, avis (favorable/défavorable/null), commentaire avis, décideur, commentaire décision, motif
            ['youssef', 'administratif', $j(-12), $j(-10), 'approuvee', 'favorable', null, 'dir', null, null],
            ['youssef', 'administratif', $j(12), $j(16), 'en_attente_avis', null, null, null, null, 'Voyage familial'],
            ['hind', 'administratif', $j(6), $j(8), 'en_attente_decision', 'favorable', 'Service couvert par Omar.', null, null, null],
            ['omar', 'administratif', $j(4), $j(8), 'en_attente_decision', 'defavorable',
                'Réception des travaux prévue cette semaine-là.', null, null, null],
            ['mehdi', 'exceptionnel', $j(-7), $j(-6), 'approuvee', 'favorable', null, 'dir', null, 'Mariage dans la famille'],
            ['mehdi', 'administratif', $j(9), $j(13), 'refusee', 'defavorable', 'Contrôles routiers programmés.', 'dir',
                "Période d'activité du service : merci de proposer d'autres dates.", null],
            ['nadia', 'administratif', $j(-1), $j(2), 'approuvee', 'favorable', null, 'dir', 'Bon congé.', null],
            ['karim', 'administratif', $j(20), $j(24), 'en_attente_decision', null, null, null, null, null],
            ['samira', 'administratif', $j(-15), $j(-13), 'approuvee', null, null, 'dir', null, null],
            ['dir', 'administratif', $j(25), $j(29), 'en_attente_decision', null, null, null, null, null],
        ];
        $insertConge = $db->prepare('INSERT INTO demandes_conges (employe_id, type, date_debut, date_fin, debut_apres_midi,
            fin_midi, nb_jours, motif, statut, avis, avis_par, avis_commentaire, avis_le, valideur_id, commentaire_valideur,
            traitee_le, cree_le) VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        // Les dates de dépôt, d'avis et de décision ne peuvent pas être dans le futur
        $passe = fn (DateTimeImmutable $date, string $heure, int $joursAvant) => min(
            $date->modify("-$joursAvant days")->setTime(...array_map('intval', explode(':', $heure))),
            $maintenant->modify('-' . $joursAvant . ' hours'),
        )->format('Y-m-d H:i:s');
        $chefs = ['youssef' => 'karim', 'hind' => 'karim', 'omar' => 'karim', 'mehdi' => 'samira', 'nadia' => 'samira'];
        $absences = [];
        foreach ($conges as [$qui, $type, $debut, $fin, $statut, $avis, $avisCommentaire, $decideur, $decision, $motif]) {
            $insertConge->execute([
                $ids[$qui], $type, $debut->format('Y-m-d'), $fin->format('Y-m-d'), Calendrier::joursOuvres($debut, $fin),
                $motif, $statut, $avis, $avis !== null ? $ids[$chefs[$qui]] : null, $avisCommentaire,
                $avis !== null ? $passe($debut, '10:15', 12) : null,
                $decideur !== null ? $ids[$decideur] : null, $decision,
                $decideur !== null ? $passe($debut, '11:40', 10) : null,
                $passe($debut, '09:05', 14),
            ]);
            if ($statut === 'approuvee') {
                for ($d = $debut; $d <= $fin; $d = $d->modify('+1 day')) {
                    $absences[$qui][$d->format('Y-m-d')] = true;
                }
            }
        }

        // ---- Pointages des 15 derniers jours travaillés, calés sur l'horaire officiel ----
        $insertPointage = $db->prepare('INSERT INTO pointages (employe_id, type, horodatage, source) VALUES (?, ?, ?, ?)');
        $badger = function (int $id, string $type, DateTimeImmutable $instant, string $source) use ($insertPointage): void {
            $insertPointage->execute([$id, $type, $instant->format('Y-m-d H:i:s'), $source]);
        };
        $a = fn (DateTimeImmutable $date, string $heure, int $decalage) => $date->setTime(...array_map('intval', explode(':', $heure)))
            ->modify(($decalage >= 0 ? '+' : '') . $decalage . ' minutes');
        foreach ($personnes as [$cle]) {
            for ($n = 15; $n >= 1; $n--) {
                $date = $j(-$n);
                if (isset($absences[$cle][$date->format('Y-m-d')]) || ($cle === 'salma' && $n > 10)) {
                    continue;
                }
                $h = Horaires::du($date);
                $source = mt_rand(0, 3) === 0 ? 'web' : 'borne';
                $retard = ($cle === 'omar' && in_array($n, [3, 8], true)) ? mt_rand(18, 35) : mt_rand(-20, 3);
                $arrivee = $a($date, $h['debut'], $retard);
                $debutPause = $a($date, $h['pause'] > 30 ? '12:30' : '12:15', mt_rand(-5, 10));
                $finPause = $debutPause->modify('+' . ($h['pause'] + mt_rand(-3, 4)) . ' minutes');
                $depart = $a($date, $h['fin'], ($cle === 'mehdi' && $n === 5) ? -55 : mt_rand(-2, 25));

                $badger($ids[$cle], 'entree', $arrivee, $source);
                if ($h['pause'] > 0) {
                    $badger($ids[$cle], 'debut_pause', $debutPause, $source);
                    $badger($ids[$cle], 'fin_pause', $finPause, $source);
                }
                if (!($cle === 'hind' && $n === 6)) {   // oubli de badge de départ
                    $badger($ids[$cle], 'sortie', $depart, $source);
                }
            }
        }

        // ---- Aujourd'hui : arrivées déjà badgées si la démonstration est installée en journée ----
        $horaire = Horaires::du($jour);
        if ($horaire !== null && (int) $maintenant->format('G') >= 9) {
            foreach (['dir', 'rh', 'karim', 'samira', 'youssef', 'hind', 'omar', 'mehdi'] as $cle) {
                if (isset($absences[$cle][$jour->format('Y-m-d')])) {
                    continue;
                }
                $arrivee = $a($jour, $horaire['debut'], $cle === 'omar' ? 22 : mt_rand(-20, 2));
                if ($arrivee < $maintenant) {
                    $badger($ids[$cle], 'entree', $arrivee, 'borne');
                }
            }
        }
    }
}
