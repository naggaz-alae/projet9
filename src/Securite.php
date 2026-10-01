<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/** Limite le nombre d'essais (mot de passe, code PIN) pour bloquer les attaques par force brute. */
final class Limiteur
{
    public static function bloque(PDO $db, string $cle, DateTimeImmutable $maintenant): bool
    {
        $requete = $db->prepare('SELECT COUNT(*) FROM tentatives_connexion WHERE cle = ? AND horodatage >= ?');
        $requete->execute([$cle, self::debutFenetre($maintenant)]);
        return (int) $requete->fetchColumn() >= (int) config('limite_essais.max', 5);
    }

    public static function echec(PDO $db, string $cle, DateTimeImmutable $maintenant): void
    {
        $db->prepare('INSERT INTO tentatives_connexion (cle, horodatage) VALUES (?, ?)')
            ->execute([$cle, $maintenant->format('Y-m-d H:i:s')]);
        // Ménage des anciennes tentatives
        $db->prepare('DELETE FROM tentatives_connexion WHERE horodatage < ?')->execute([self::debutFenetre($maintenant)]);
    }

    public static function reinitialiser(PDO $db, string $cle): void
    {
        $db->prepare('DELETE FROM tentatives_connexion WHERE cle = ?')->execute([$cle]);
    }

    private static function debutFenetre(DateTimeImmutable $maintenant): string
    {
        return $maintenant->modify('-' . (int) config('limite_essais.minutes', 15) . ' minutes')->format('Y-m-d H:i:s');
    }
}

/** Jeton anti-CSRF : chaque formulaire POST doit renvoyer le jeton de la session. */
final class Csrf
{
    public static function jeton(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['csrf'];
    }

    public static function champ(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::jeton()) . '">';
    }

    public static function verifier(): void
    {
        $recu = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($recu) || !hash_equals(self::jeton(), $recu)) {
            if (veut_json()) {
                repondre_json(['ok' => false, 'message' => 'Session expirée : rechargez la page.'], 419);
            }
            http_response_code(419);
            exit('Session expirée : revenez en arrière et rechargez la page.');
        }
    }
}

final class Auth
{
    private static ?array $utilisateur = null;

    /**
     * Vérifie e-mail + mot de passe. Les messages d'erreur ne disent jamais
     * si c'est l'e-mail ou le mot de passe qui est faux.
     *
     * @return array<string, mixed>
     */
    public static function verifier(PDO $db, string $email, string $motDePasse, DateTimeImmutable $maintenant): array
    {
        $email = mb_strtolower(trim($email));
        $cle = 'web:' . $email;
        if (Limiteur::bloque($db, $cle, $maintenant)) {
            throw new ErreurMetier('Trop de tentatives. Réessayez dans quelques minutes.');
        }
        $requete = $db->prepare('SELECT * FROM employes WHERE email = ? AND actif = 1');
        $requete->execute([$email]);
        $employe = $requete->fetch();
        if (!$employe || !password_verify($motDePasse, (string) $employe['mot_de_passe'])) {
            Limiteur::echec($db, $cle, $maintenant);
            throw new ErreurMetier('Identifiants incorrects.');
        }
        Limiteur::reinitialiser($db, $cle);
        if (password_needs_rehash((string) $employe['mot_de_passe'], PASSWORD_DEFAULT)) {
            $db->prepare('UPDATE employes SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($motDePasse, PASSWORD_DEFAULT), $employe['id']]);
        }
        return $employe;
    }

    /** Identification à la borne : matricule + code PIN. */
    public static function verifierPin(PDO $db, string $matricule, string $pin, DateTimeImmutable $maintenant): array
    {
        $matricule = mb_strtoupper(trim($matricule));
        $cle = 'borne:' . $matricule;
        if (Limiteur::bloque($db, $cle, $maintenant)) {
            throw new ErreurMetier('Trop de tentatives sur ce matricule. Réessayez dans quelques minutes.');
        }
        $requete = $db->prepare('SELECT * FROM employes WHERE matricule = ? AND actif = 1 AND pin IS NOT NULL');
        $requete->execute([$matricule]);
        $employe = $requete->fetch();
        if (!$employe || !password_verify($pin, (string) $employe['pin'])) {
            Limiteur::echec($db, $cle, $maintenant);
            throw new ErreurMetier('Matricule ou code PIN incorrect.');
        }
        Limiteur::reinitialiser($db, $cle);
        return $employe;
    }

    public static function ouvrirSession(array $employe): void
    {
        session_regenerate_id(true);   // protection contre la fixation de session
        $_SESSION['employe_id'] = (int) $employe['id'];
        unset($_SESSION['csrf']);
    }

    public static function fermerSession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** @return array<string, mixed>|null utilisateur connecté (rechargé depuis la base : un compte désactivé est déconnecté) */
    public static function utilisateur(PDO $db): ?array
    {
        if (self::$utilisateur === null && isset($_SESSION['employe_id'])) {
            $requete = $db->prepare('SELECT id, matricule, prenom, nom, email, role, manager_id, service, date_entree
                                     FROM employes WHERE id = ? AND actif = 1');
            $requete->execute([(int) $_SESSION['employe_id']]);
            self::$utilisateur = $requete->fetch() ?: null;
        }
        return self::$utilisateur;
    }

    /**
     * À appeler en haut de chaque page protégée.
     *
     * @return array<string, mixed>
     */
    public static function exiger(PDO $db, string ...$roles): array
    {
        $utilisateur = self::utilisateur($db);
        if ($utilisateur === null) {
            if (veut_json()) {
                repondre_json(['ok' => false, 'message' => 'Session expirée : reconnectez-vous.'], 401);
            }
            rediriger('connexion.php');
        }
        if ($roles !== [] && !in_array($utilisateur['role'], $roles, true)) {
            if (veut_json()) {
                repondre_json(['ok' => false, 'message' => 'Accès refusé.'], 403);
            }
            http_response_code(403);
            entete('Accès refusé', $utilisateur);
            echo '<section class="carte vide"><h1>Accès refusé</h1><p>Cette page est réservée à un autre profil.</p>'
                . '<p><a class="bouton" href="' . e(url('tableau-de-bord.php')) . '">Retour au tableau de bord</a></p></section>';
            pied();
            exit;
        }
        return $utilisateur;
    }
}
