<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/** Fiches des agents et périmètres de visibilité selon le rôle. */
final class Employes
{
    public const ROLES = [
        'fonctionnaire' => 'Fonctionnaire',
        'chef_service' => 'Chef de service',
        'directeur' => 'Directeur provincial',
        'personnel' => 'Bureau du personnel',
    ];

    /** Rôles qui voient toute la direction */
    public const VISION_GLOBALE = ['directeur', 'personnel'];

    private const COLONNES = 'id, matricule, prenom, nom, email, role, manager_id, service, date_entree, actif';

    public function __construct(private PDO $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function parId(int $id): ?array
    {
        $requete = $this->db->prepare('SELECT ' . self::COLONNES . ' FROM employes WHERE id = ?');
        $requete->execute([$id]);
        return $requete->fetch() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function tous(bool $actifsSeulement = true): array
    {
        $filtre = $actifsSeulement ? 'WHERE actif = 1' : '';
        return $this->db->query('SELECT ' . self::COLONNES . " FROM employes $filtre ORDER BY nom, prenom")->fetchAll();
    }

    /** @return list<array<string, mixed>> personnes pouvant être désignées comme supérieur hiérarchique */
    public function responsables(): array
    {
        return $this->db->query('SELECT ' . self::COLONNES . " FROM employes
            WHERE actif = 1 AND role IN ('chef_service', 'directeur') ORDER BY nom, prenom")->fetchAll();
    }

    /**
     * Personnes visibles dans les vues d'équipe :
     * directeur et bureau du personnel -> toute la direction ; chef -> lui-même + son service ;
     * fonctionnaire -> les collègues de son service.
     *
     * @return list<array<string, mixed>>
     */
    public function perimetre(array $utilisateur): array
    {
        if (in_array($utilisateur['role'], self::VISION_GLOBALE, true)) {
            return $this->tous();
        }
        $reference = $utilisateur['role'] === 'chef_service' ? (int) $utilisateur['id'] : $utilisateur['manager_id'];
        if ($reference === null) {
            return [$utilisateur];
        }
        $requete = $this->db->prepare('SELECT ' . self::COLONNES . ' FROM employes
            WHERE actif = 1 AND (id = ? OR manager_id = ?) ORDER BY nom, prenom');
        $requete->execute([(int) $utilisateur['id'], (int) $reference]);
        return $requete->fetchAll();
    }

    /** Qui peut voir les pointages de qui : soi-même, son chef de service, le directeur, le bureau du personnel. */
    public static function peutConsulter(array $utilisateur, array $cible): bool
    {
        return (int) $utilisateur['id'] === (int) $cible['id']
            || in_array($utilisateur['role'], self::VISION_GLOBALE, true)
            || ($utilisateur['role'] === 'chef_service' && (int) $cible['manager_id'] === (int) $utilisateur['id']);
    }

    /**
     * Création ou modification d'une fiche (bureau du personnel). Renvoie l'identifiant.
     *
     * @param array<string, string> $donnees
     */
    public function enregistrer(array $donnees, ?int $id, DateTimeImmutable $maintenant): int
    {
        $matricule = mb_strtoupper(trim($donnees['matricule'] ?? ''));
        $prenom = trim($donnees['prenom'] ?? '');
        $nom = trim($donnees['nom'] ?? '');
        $email = mb_strtolower(trim($donnees['email'] ?? ''));
        $role = $donnees['role'] ?? 'fonctionnaire';
        $service = trim($donnees['service'] ?? '');
        $managerId = filter_var($donnees['manager_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
        $dateEntree = trim($donnees['date_entree'] ?? '');
        $motDePasse = $donnees['mot_de_passe'] ?? '';
        $pin = trim($donnees['pin'] ?? '');

        if (!preg_match('/^[A-Z0-9-]{2,20}$/', $matricule)) {
            throw new ErreurMetier('Matricule invalide (2 à 20 lettres, chiffres ou tirets).');
        }
        if ($prenom === '' || $nom === '') {
            throw new ErreurMetier('Le prénom et le nom sont obligatoires.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ErreurMetier('Adresse e-mail invalide.');
        }
        if (!isset(self::ROLES[$role])) {
            throw new ErreurMetier('Rôle invalide.');
        }
        if ($dateEntree !== '' && date_valide($dateEntree) === null) {
            throw new ErreurMetier("Date d'entrée en fonction invalide.");
        }
        if ($managerId !== null && ($managerId === $id || $this->parId($managerId) === null)) {
            throw new ErreurMetier('Supérieur hiérarchique invalide.');
        }
        if (($id === null || $motDePasse !== '') && mb_strlen($motDePasse) < 8) {
            throw new ErreurMetier('Le mot de passe doit contenir au moins 8 caractères.');
        }
        if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) {
            throw new ErreurMetier('Le code PIN doit contenir 4 à 6 chiffres.');
        }

        $doublon = $this->db->prepare('SELECT id FROM employes WHERE (matricule = ? OR email = ?) AND id <> ?');
        $doublon->execute([$matricule, $email, $id ?? 0]);
        if ($doublon->fetch()) {
            throw new ErreurMetier('Ce matricule ou cette adresse e-mail est déjà utilisé.');
        }

        $valeurs = [$matricule, $prenom, $nom, $email, $role, $managerId, $service ?: null, $dateEntree ?: null];
        if ($id === null) {
            $this->db->prepare(
                'INSERT INTO employes (matricule, prenom, nom, email, role, manager_id, service, date_entree,
                                       mot_de_passe, pin, actif, cree_le)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            )->execute([...$valeurs, password_hash($motDePasse, PASSWORD_DEFAULT),
                $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null, $maintenant->format('Y-m-d H:i:s')]);
            $id = (int) $this->db->lastInsertId();
        } else {
            $this->db->prepare(
                'UPDATE employes SET matricule = ?, prenom = ?, nom = ?, email = ?, role = ?, manager_id = ?,
                        service = ?, date_entree = ?, actif = ? WHERE id = ?'
            )->execute([...$valeurs, isset($donnees['actif']) ? 1 : 0, $id]);
            if ($motDePasse !== '') {
                $this->db->prepare('UPDATE employes SET mot_de_passe = ? WHERE id = ?')
                    ->execute([password_hash($motDePasse, PASSWORD_DEFAULT), $id]);
            }
            if ($pin !== '') {
                $this->db->prepare('UPDATE employes SET pin = ? WHERE id = ?')
                    ->execute([password_hash($pin, PASSWORD_DEFAULT), $id]);
            }
        }

        // Droits de l'année en cours (report exceptionnel inclus, le cas échéant)
        $annee = (int) $maintenant->format('Y');
        foreach (array_keys(Conges::TYPES) as $type) {
            $jours = filter_var(str_replace(',', '.', $donnees["droits_$type"] ?? ''), FILTER_VALIDATE_FLOAT);
            if ($jours !== false && $jours >= 0 && $jours <= 60) {
                $this->definirDroits($id, $type, $annee, $jours);
            }
        }
        return $id;
    }

    public function definirDroits(int $employeId, string $type, int $annee, float $jours): void
    {
        $this->db->prepare('DELETE FROM soldes WHERE employe_id = ? AND type = ? AND annee = ?')->execute([$employeId, $type, $annee]);
        $this->db->prepare('INSERT INTO soldes (employe_id, type, annee, jours_acquis) VALUES (?, ?, ?, ?)')
            ->execute([$employeId, $type, $annee, $jours]);
    }

    public static function nomComplet(array $employe): string
    {
        return $employe['prenom'] . ' ' . $employe['nom'];
    }
}
