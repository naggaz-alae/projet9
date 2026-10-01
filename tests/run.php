<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/*
 * Mini-framework de test en PHP pur (aucune dépendance à installer).
 * Lancement : php tests/run.php
 */

require __DIR__ . '/../src/bootstrap.php';

$GLOBALS['tests'] = [];

function test(string $nom, callable $fonction): void
{
    $GLOBALS['tests'][$nom] = $fonction;
}

function egal(mixed $attendu, mixed $obtenu, string $contexte = ''): void
{
    if ($attendu !== $obtenu) {
        throw new AssertionError(sprintf("%sattendu %s, obtenu %s", $contexte ? "$contexte : " : '',
            var_export($attendu, true), var_export($obtenu, true)));
    }
}

function vrai(bool $condition, string $message = 'condition fausse'): void
{
    if (!$condition) {
        throw new AssertionError($message);
    }
}

/** Vérifie qu'une ErreurMetier est levée, et que son message contient $extrait. */
function erreur_metier(callable $fonction, string $extrait = ''): void
{
    try {
        $fonction();
    } catch (ErreurMetier $e) {
        vrai($extrait === '' || str_contains($e->getMessage(), $extrait),
            "message « {$e->getMessage()} » sans « $extrait »");
        return;
    }
    throw new AssertionError('ErreurMetier attendue, aucune levée');
}

/** Base SQLite en mémoire avec le vrai schéma : chaque test part d'une base vide. */
function base_de_test(): PDO
{
    Calendrier::definirFeriesVariables([]);   // chaque test part d'un calendrier sans fête religieuse saisie
    Horaires::definirSpeciaux([]);
    $db = Database::ouvrir('sqlite::memory:');
    Installateur::creerSchema($db);
    return $db;
}

/** @return int identifiant */
function creer_employe(PDO $db, string $matricule, string $role = 'fonctionnaire', ?int $managerId = null,
                       ?string $dateEntree = '2015-01-01', string $pin = '1234'): int
{
    $db->prepare('INSERT INTO employes (matricule, prenom, nom, email, mot_de_passe, pin, role, manager_id, date_entree, actif, cree_le)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)')
        ->execute([$matricule, 'Prénom', $matricule, strtolower($matricule) . '@test.ma',
            password_hash('motdepasse', PASSWORD_BCRYPT, ['cost' => 4]),
            password_hash($pin, PASSWORD_BCRYPT, ['cost' => 4]), $role, $managerId, $dateEntree, '2021-03-01 00:00:00']);
    return (int) $db->lastInsertId();
}

/** @return array<string, mixed> */
function employe(PDO $db, int $id): array
{
    $requete = $db->prepare('SELECT * FROM employes WHERE id = ?');
    $requete->execute([$id]);
    return $requete->fetch();
}

foreach (glob(__DIR__ . '/test_*.php') as $fichier) {
    require $fichier;
}

$echecs = 0;
foreach ($GLOBALS['tests'] as $nom => $fonction) {
    try {
        Calendrier::definirFeriesVariables([]);
        Horaires::definirSpeciaux([]);
        $fonction();
        echo "  \033[32m✓\033[0m $nom\n";
    } catch (Throwable $e) {
        $echecs++;
        echo "  \033[31m✗ $nom\033[0m\n      " . get_class($e) . ' : ' . $e->getMessage()
            . "\n      " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
}
$total = count($GLOBALS['tests']);
echo "\n" . ($total - $echecs) . "/$total tests réussis\n";
exit($echecs === 0 ? 0 : 1);
