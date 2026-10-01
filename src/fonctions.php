<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/** Petites fonctions utilitaires partagées par toutes les pages. */

final class ErreurMetier extends RuntimeException
{
    // Erreur « normale » (règle de gestion non respectée) : son message est affiché à l'utilisateur.
}

const NOMS_JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const NOMS_MOIS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
    'septembre', 'octobre', 'novembre', 'décembre'];

/** Lecture d'une valeur de configuration : config('db.dsn'). */
function config(string $cle, mixed $defaut = null): mixed
{
    $valeur = $GLOBALS['config'] ?? [];
    foreach (explode('.', $cle) as $partie) {
        if (!is_array($valeur) || !array_key_exists($partie, $valeur)) {
            return $defaut;
        }
        $valeur = $valeur[$partie];
    }
    return $valeur;
}

/** Échappement HTML : TOUTE donnée affichée passe par cette fonction (protection XSS). */
function e(string|int|float|null $texte): string
{
    return htmlspecialchars((string) $texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $chemin = ''): string
{
    return rtrim((string) config('base_url', ''), '/') . '/' . ltrim($chemin, '/');
}

function rediriger(string $chemin): never
{
    header('Location: ' . url($chemin));
    exit;
}

function est_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function veut_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/** @param array<string, mixed> $donnees */
function repondre_json(array $donnees, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** @return list<array{type: string, message: string}> */
function flashs(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Paramètre texte d'une requête (GET ou POST), toujours une chaîne. */
function param(string $nom, string $defaut = ''): string
{
    $valeur = $_POST[$nom] ?? $_GET[$nom] ?? $defaut;
    return is_string($valeur) ? trim($valeur) : $defaut;
}

function param_entier(string $nom): ?int
{
    $valeur = filter_var(param($nom), FILTER_VALIDATE_INT);
    return $valeur === false ? null : $valeur;
}

function date_valide(string $texte): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $texte);
    return ($date !== false && $date->format('Y-m-d') === $texte) ? $date : null;
}

/** 27000 -> « 7 h 30 » ; -1800 -> « −0 h 30 » */
function duree(int $secondes): string
{
    $signe = $secondes < 0 ? '−' : '';
    $secondes = abs($secondes);
    return sprintf('%s%d h %02d', $signe, intdiv($secondes, 3600), intdiv($secondes % 3600, 60));
}

/** 7.5 -> « 7,5 » ; 3.0 -> « 3 » */
function nombre(float $valeur): string
{
    return rtrim(rtrim(number_format($valeur, 1, ',', ' '), '0'), ',');
}

function date_longue(DateTimeInterface $date): string
{
    return sprintf('%s %d %s %s', NOMS_JOURS[(int) $date->format('N')], (int) $date->format('j'),
        NOMS_MOIS[(int) $date->format('n')], $date->format('Y'));
}

function date_courte(string $ymd): string
{
    $date = date_valide(substr($ymd, 0, 10));
    return $date ? $date->format('d/m/Y') : $ymd;
}

function heure(string $horodatage): string
{
    return substr($horodatage, 11, 5);
}

function aujourdhui(DateTimeImmutable $maintenant): DateTimeImmutable
{
    return $maintenant->setTime(0, 0);
}

function lundi_de(DateTimeImmutable $jour): DateTimeImmutable
{
    return $jour->setTime(0, 0)->modify('-' . ((int) $jour->format('N') - 1) . ' days');
}
