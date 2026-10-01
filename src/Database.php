<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

final class Database
{
    private static ?PDO $connexion = null;

    /** Connexion unique pour toute la requête, ouverte à la première utilisation. */
    public static function connexion(): PDO
    {
        return self::$connexion ??= self::ouvrir(
            (string) config('db.dsn'),
            config('db.utilisateur'),
            config('db.mot_de_passe'),
        );
    }

    public static function ouvrir(string $dsn, ?string $utilisateur = null, ?string $motDePasse = null): PDO
    {
        $pdo = new PDO($dsn, $utilisateur, $motDePasse, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,   // vraies requêtes préparées : pas d'injection SQL
        ]);
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
        return $pdo;
    }

    /** Utilisé par les tests pour injecter une base en mémoire. */
    public static function definir(PDO $pdo): void
    {
        self::$connexion = $pdo;
    }
}
