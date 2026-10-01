<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/*
 * Configuration par défaut.
 * Pour la modifier sans toucher à ce fichier : créer config/config.local.php
 * (ignoré par Git) qui renvoie un tableau avec uniquement les clés à remplacer,
 * ou utiliser les variables d'environnement DB_DSN, DB_USER, DB_PASSWORD, APP_BASE_URL.
 */

$config = [
    'nom_app' => 'Temps & Congés',

    // Contexte du projet, affiché sur la page de connexion et en pied de page
    'contexte' => 'Projet réalisé lors de mon stage à la Direction provinciale du Transport et de la Logistique '
        . 'de Bouarfa (Maroc), de mars à juillet 2021',
    'fuseau' => 'Africa/Casablanca',

    // Préfixe des URL si l'application est installée dans un sous-dossier (ex. '/rh')
    'base_url' => getenv('APP_BASE_URL') ?: '',

    // Affiche les comptes de démonstration sur la page de connexion
    'demo' => true,

    'db' => [
        // SQLite par défaut. MySQL : 'mysql:host=localhost;dbname=rh;charset=utf8mb4'
        'dsn' => getenv('DB_DSN') ?: 'sqlite:' . dirname(__DIR__) . '/var/rh.sqlite',
        'utilisateur' => getenv('DB_USER') ?: null,
        'mot_de_passe' => getenv('DB_PASSWORD') ?: null,
    ],

    /*
     * Horaires des administrations publiques (décret n° 2-05-916 du 20 juillet 2005, art. 1) :
     * du lundi au vendredi, de 8h30 à 16h30, pause de 30 minutes,
     * prolongée d'une heure le vendredi pour la prière.
     * Les horaires du ramadan (art. 2) se saisissent chaque année dans la page « Calendrier ».
     */
    'horaires' => [
        'standard' => ['debut' => '08:30', 'fin' => '16:30', 'pause' => 30],
        'vendredi' => ['debut' => '08:30', 'fin' => '16:30', 'pause' => 90],
        // Marge avant de signaler un retard ou un départ anticipé
        'tolerance_minutes' => 5,
    ],

    /*
     * Droits annuels par défaut (en jours de travail) :
     * - congé administratif : 22 jours ouvrables par an (Statut général de la fonction publique, art. 40) ;
     * - congé exceptionnel pour événements familiaux : 10 jours maximum (art. 41).
     * Le bureau du personnel peut ajuster les droits d'une année (report exceptionnel, etc.).
     */
    'droits_annuels' => ['administratif' => 22, 'exceptionnel' => 10],

    // Protection contre les essais de mots de passe ou de codes PIN en série
    'limite_essais' => ['max' => 5, 'minutes' => 15],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

return $config;
