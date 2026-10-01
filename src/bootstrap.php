<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/* Point d'entrée commun : chaque page commence par require ce fichier. */

define('RACINE', dirname(__DIR__));

require __DIR__ . '/fonctions.php';
$GLOBALS['config'] = require RACINE . '/config/config.php';
date_default_timezone_set((string) config('fuseau', 'Europe/Paris'));

require __DIR__ . '/Database.php';
require __DIR__ . '/Calendrier.php';
require __DIR__ . '/Horaires.php';
require __DIR__ . '/Pointage.php';
require __DIR__ . '/PointageService.php';
require __DIR__ . '/Conges.php';
require __DIR__ . '/Employes.php';
require __DIR__ . '/Securite.php';
require __DIR__ . '/Installateur.php';
require __DIR__ . '/vue.php';

// Fêtes religieuses et horaires particuliers : lus en base au premier besoin seulement
Calendrier::definirChargeur(static function (): array {
    try {
        return Database::connexion()->query('SELECT date_ferie, nom FROM feries_variables')
            ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException) {
        return [];   // base pas encore installée
    }
});
Horaires::definirChargeur(static function (): array {
    try {
        return Database::connexion()->query('SELECT * FROM horaires_speciaux ORDER BY date_debut')->fetchAll();
    } catch (PDOException) {
        return [];
    }
});

if (PHP_SAPI !== 'cli') {
    // En-têtes de sécurité
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'");

    session_name('TCSESSION');
    session_set_cookie_params([
        'httponly' => true,                       // cookie illisible en JavaScript
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
