<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/*
 * Installation de la base de données (en ligne de commande) :
 *   php database/installer.php                 crée les tables
 *   php database/installer.php --demo          crée les tables + données de démonstration
 *   php database/installer.php --reinitialiser --demo   repart de zéro (SUPPRIME les données)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../src/bootstrap.php';

$options = array_slice($argv, 1);
$dsn = (string) config('db.dsn');

if (str_starts_with($dsn, 'sqlite:')) {
    $dossier = dirname(substr($dsn, 7));
    if (!is_dir($dossier) && !mkdir($dossier, 0775, true)) {
        fwrite(STDERR, "Impossible de créer le dossier $dossier\n");
        exit(1);
    }
}

$db = Database::connexion();

if (in_array('--reinitialiser', $options, true)) {
    Installateur::supprimerTables($db);
    echo "Tables supprimées.\n";
}

Installateur::creerSchema($db);
echo "Schéma créé (" . $db->getAttribute(PDO::ATTR_DRIVER_NAME) . ").\n";

if (in_array('--demo', $options, true)) {
    if ((int) $db->query('SELECT COUNT(*) FROM employes')->fetchColumn() > 0) {
        fwrite(STDERR, "La base contient déjà des employés : utilisez --reinitialiser --demo pour repartir de zéro.\n");
        exit(1);
    }
    Installateur::chargerDemo($db, new DateTimeImmutable());
    echo "Données de démonstration chargées.\n\n";
    echo "Comptes (mot de passe : demo1234)\n";
    echo "  Fonctionnaire        fonctionnaire@demo.test\n  Chef de service      chef@demo.test\n";
    echo "  Directeur            directeur@demo.test\n  Bureau du personnel  personnel@demo.test\n";
    echo "Borne de badgeage : matricule F001, code PIN 1234\n";
}
