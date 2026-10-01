<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/**
 * Congés de la fonction publique, avec un circuit de validation à deux niveaux :
 *
 *   dépôt ──► avis du chef de service ──► décision du directeur provincial ──► accordé / refusé
 *            (favorable ou défavorable,      (le directeur décide en
 *             motivé si défavorable)           connaissance de l'avis)
 *
 * Une demande déposée par un chef de service (ou par un agent sans chef) va directement au directeur.
 * Une demande du directeur est enregistrée par le bureau du personnel (autorisation de la hiérarchie).
 */
final class Conges
{
    public const TYPES = [
        'administratif' => 'Congé administratif',
        'exceptionnel' => 'Congé exceptionnel',
    ];

    public const AIDE_TYPES = [
        'administratif' => '22 jours ouvrables par an (art. 40 du Statut général de la fonction publique)',
        'exceptionnel' => 'Événements familiaux graves, 10 jours maximum par an (art. 41)',
    ];

    public const STATUTS = [
        'en_attente_avis' => 'Avis du chef en attente',
        'en_attente_decision' => 'Décision en attente',
        'approuvee' => 'Accordé',
        'refusee' => 'Refusé',
        'annulee' => 'Annulé',
    ];

    public const EN_COURS = ['en_attente_avis', 'en_attente_decision'];

    private const SELECT_DEMANDE = 'SELECT d.*, e.prenom, e.nom, e.matricule, e.service, e.manager_id, e.role AS role_demandeur,
            a.prenom AS avis_prenom, a.nom AS avis_nom
        FROM demandes_conges d
        JOIN employes e ON e.id = d.employe_id
        LEFT JOIN employes a ON a.id = d.avis_par';

    public function __construct(private PDO $db)
    {
    }

    /** Droits de l'année : valeur saisie par le bureau du personnel, sinon droits par défaut. */
    public function droits(int $employeId, string $type, int $annee): float
    {
        $requete = $this->db->prepare('SELECT jours_acquis FROM soldes WHERE employe_id = ? AND type = ? AND annee = ?');
        $requete->execute([$employeId, $type, $annee]);
        $valeur = $requete->fetchColumn();
        return $valeur !== false ? (float) $valeur : (float) (config('droits_annuels')[$type] ?? 0);
    }

    /**
     * Soldes d'une année : acquis, pris (accordés), en cours de validation, disponible.
     *
     * @return array<string, array{acquis: float, pris: float, en_attente: float, disponible: float}>
     */
    public function soldes(int $employeId, int $annee, ?int $exclureDemande = null): array
    {
        $soldes = [];
        foreach (array_keys(self::TYPES) as $type) {
            $soldes[$type] = ['acquis' => $this->droits($employeId, $type, $annee), 'pris' => 0.0, 'en_attente' => 0.0, 'disponible' => 0.0];
        }
        $requete = $this->db->prepare(
            "SELECT type, statut, SUM(nb_jours) AS total FROM demandes_conges
             WHERE employe_id = ? AND statut IN ('en_attente_avis', 'en_attente_decision', 'approuvee')
               AND date_debut >= ? AND date_debut <= ? AND id <> ?
             GROUP BY type, statut"
        );
        $requete->execute([$employeId, "$annee-01-01", "$annee-12-31", $exclureDemande ?? 0]);
        foreach ($requete->fetchAll() as $ligne) {
            $cle = $ligne['statut'] === 'approuvee' ? 'pris' : 'en_attente';
            $soldes[$ligne['type']][$cle] += (float) $ligne['total'];
        }
        foreach ($soldes as $type => $solde) {
            $soldes[$type]['disponible'] = $solde['acquis'] - $solde['pris'] - $solde['en_attente'];
        }
        return $soldes;
    }

    /** Dépose une demande après contrôle des règles. Renvoie son identifiant. */
    public function demander(
        int $employeId,
        string $type,
        string $debut,
        string $fin,
        bool $debutApresMidi,
        bool $finMidi,
        string $motif,
        DateTimeImmutable $maintenant,
    ): int {
        if (!isset(self::TYPES[$type])) {
            throw new ErreurMetier('Type de congé inconnu.');
        }
        $dateDebut = date_valide($debut);
        $dateFin = date_valide($fin);
        if ($dateDebut === null || $dateFin === null) {
            throw new ErreurMetier('Dates invalides.');
        }
        if ($dateDebut < aujourdhui($maintenant)) {
            throw new ErreurMetier('Un congé ne peut pas commencer dans le passé.');
        }
        if ($dateDebut->format('Y') !== $dateFin->format('Y')) {
            throw new ErreurMetier('Les droits sont annuels : une demande à cheval sur deux années doit être scindée en deux.');
        }
        if ($type === 'exceptionnel' && trim($motif) === '') {
            throw new ErreurMetier("Un congé exceptionnel doit préciser l'événement familial concerné.");
        }

        $demandeur = $this->employe($employeId) ?? throw new ErreurMetier('Agent introuvable.');
        if ($type === 'administratif' && $demandeur['date_entree']) {
            $ouverture = (new DateTimeImmutable((string) $demandeur['date_entree']))->modify('+12 months');
            if ($dateDebut < $ouverture) {
                throw new ErreurMetier(sprintf(
                    'Le premier congé administratif est accordé après douze mois de service, soit à partir du %s.',
                    $ouverture->format('d/m/Y'),
                ));
            }
        }

        $nbJours = Calendrier::joursOuvres($dateDebut, $dateFin, $debutApresMidi, $finMidi);
        if ($nbJours <= 0) {
            throw new ErreurMetier('La période choisie ne contient aucun jour travaillé.');
        }

        $conflit = $this->chevauchement($employeId, $debut, $fin);
        if ($conflit !== null) {
            throw new ErreurMetier(sprintf(
                'Cette période chevauche une demande existante (%s, du %s au %s).',
                mb_strtolower(self::STATUTS[$conflit['statut']]),
                date_courte($conflit['date_debut']),
                date_courte($conflit['date_fin']),
            ));
        }

        $annee = (int) $dateDebut->format('Y');
        $disponible = $this->soldes($employeId, $annee)[$type]['disponible'];
        if ($disponible < $nbJours) {
            throw new ErreurMetier(sprintf(
                'Solde insuffisant : %s jour(s) demandé(s), %s disponible(s) en %s pour %d.',
                nombre($nbJours), nombre($disponible), mb_strtolower(self::TYPES[$type]), $annee,
            ));
        }

        $this->db->prepare(
            'INSERT INTO demandes_conges
               (employe_id, type, date_debut, date_fin, debut_apres_midi, fin_midi, nb_jours, motif, statut, cree_le)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $employeId, $type, $debut, $fin, (int) $debutApresMidi, (int) $finMidi, $nbJours,
            mb_substr(trim($motif), 0, 255) ?: null, $this->statutInitial($demandeur), $maintenant->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** L'avis du chef n'est demandé que si l'agent est rattaché à un chef de service. */
    private function statutInitial(array $demandeur): string
    {
        if ($demandeur['manager_id'] === null) {
            return 'en_attente_decision';
        }
        $chef = $this->employe((int) $demandeur['manager_id']);
        return ($chef !== null && $chef['role'] === 'chef_service') ? 'en_attente_avis' : 'en_attente_decision';
    }

    /** @return array<string, mixed>|null */
    private function employe(int $id): ?array
    {
        $requete = $this->db->prepare('SELECT id, role, manager_id, date_entree FROM employes WHERE id = ?');
        $requete->execute([$id]);
        return $requete->fetch() ?: null;
    }

    /** @return array<string, mixed>|null première demande active qui chevauche la période */
    public function chevauchement(int $employeId, string $debut, string $fin, ?int $exclure = null): ?array
    {
        $requete = $this->db->prepare(
            "SELECT * FROM demandes_conges
             WHERE employe_id = ? AND statut IN ('en_attente_avis', 'en_attente_decision', 'approuvee')
               AND date_debut <= ? AND date_fin >= ? AND id <> ?
             ORDER BY date_debut LIMIT 1"
        );
        $requete->execute([$employeId, $fin, $debut, $exclure ?? 0]);
        return $requete->fetch() ?: null;
    }

    /** @return array<string, mixed>|null */
    public function demande(int $id): ?array
    {
        $requete = $this->db->prepare(self::SELECT_DEMANDE . ' WHERE d.id = ?');
        $requete->execute([$id]);
        return $requete->fetch() ?: null;
    }

    /** Niveau 1 : le chef de service donne son avis sur les demandes de son service. */
    public static function peutDonnerAvis(array $utilisateur, array $demande): bool
    {
        return $demande['statut'] === 'en_attente_avis'
            && $utilisateur['role'] === 'chef_service'
            && (int) $demande['manager_id'] === (int) $utilisateur['id'];
    }

    /** Niveau 2 : le directeur décide ; les demandes du directeur sont enregistrées par le bureau du personnel. */
    public static function peutDecider(array $utilisateur, array $demande): bool
    {
        if ($demande['statut'] !== 'en_attente_decision' || (int) $utilisateur['id'] === (int) $demande['employe_id']) {
            return false;
        }
        return $utilisateur['role'] === 'directeur'
            || ($utilisateur['role'] === 'personnel' && $demande['role_demandeur'] === 'directeur');
    }

    public function donnerAvis(int $demandeId, array $chef, bool $favorable, string $commentaire, DateTimeImmutable $maintenant): void
    {
        $demande = $this->demande($demandeId) ?? throw new ErreurMetier('Demande introuvable.');
        if ($demande['statut'] !== 'en_attente_avis') {
            throw new ErreurMetier("L'avis sur cette demande a déjà été donné.");
        }
        if (!self::peutDonnerAvis($chef, $demande)) {
            throw new ErreurMetier("Vous n'êtes pas habilité à donner un avis sur cette demande.");
        }
        $commentaire = trim($commentaire);
        if (!$favorable && $commentaire === '') {
            throw new ErreurMetier('Un avis défavorable doit être motivé : ajoutez un commentaire.');
        }
        $this->db->prepare(
            "UPDATE demandes_conges SET statut = 'en_attente_decision', avis = ?, avis_par = ?, avis_commentaire = ?, avis_le = ?
             WHERE id = ? AND statut = 'en_attente_avis'"
        )->execute([$favorable ? 'favorable' : 'defavorable', (int) $chef['id'], mb_substr($commentaire, 0, 255) ?: null,
            $maintenant->format('Y-m-d H:i:s'), $demandeId]);
    }

    public function decider(int $demandeId, array $decideur, bool $accorder, string $commentaire, DateTimeImmutable $maintenant): void
    {
        $demande = $this->demande($demandeId) ?? throw new ErreurMetier('Demande introuvable.');
        if ($demande['statut'] === 'en_attente_avis') {
            throw new ErreurMetier("Cette demande attend encore l'avis du chef de service.");
        }
        if ($demande['statut'] !== 'en_attente_decision') {
            throw new ErreurMetier('Cette demande a déjà été traitée.');
        }
        if (!self::peutDecider($decideur, $demande)) {
            throw new ErreurMetier("Vous n'êtes pas habilité à décider de cette demande.");
        }
        $commentaire = trim($commentaire);
        if (!$accorder && $commentaire === '') {
            throw new ErreurMetier('Un refus doit être motivé : ajoutez un commentaire.');
        }
        if ($accorder) {
            $annee = (int) substr((string) $demande['date_debut'], 0, 4);
            $disponible = $this->soldes((int) $demande['employe_id'], $annee, $demandeId)[$demande['type']]['disponible'];
            if ($disponible < (float) $demande['nb_jours']) {
                throw new ErreurMetier('Solde devenu insuffisant pour accorder ce congé.');
            }
        }
        $this->db->prepare(
            "UPDATE demandes_conges SET statut = ?, valideur_id = ?, commentaire_valideur = ?, traitee_le = ?
             WHERE id = ? AND statut = 'en_attente_decision'"
        )->execute([$accorder ? 'approuvee' : 'refusee', (int) $decideur['id'], mb_substr($commentaire, 0, 255) ?: null,
            $maintenant->format('Y-m-d H:i:s'), $demandeId]);
    }

    public function annuler(int $demandeId, int $employeId, DateTimeImmutable $maintenant): void
    {
        $demande = $this->demande($demandeId);
        if ($demande === null || (int) $demande['employe_id'] !== $employeId) {
            throw new ErreurMetier('Demande introuvable.');
        }
        if (!in_array($demande['statut'], self::EN_COURS, true)) {
            throw new ErreurMetier('Seule une demande en cours de validation peut être annulée. Contactez le bureau du personnel.');
        }
        $this->db->prepare("UPDATE demandes_conges SET statut = 'annulee', traitee_le = ? WHERE id = ?")
            ->execute([$maintenant->format('Y-m-d H:i:s'), $demandeId]);
    }

    /** @return list<array<string, mixed>> */
    public function demandesDe(int $employeId): array
    {
        $requete = $this->db->prepare(
            'SELECT d.*, v.prenom AS valideur_prenom, v.nom AS valideur_nom, a.prenom AS avis_prenom, a.nom AS avis_nom
             FROM demandes_conges d
             LEFT JOIN employes v ON v.id = d.valideur_id
             LEFT JOIN employes a ON a.id = d.avis_par
             WHERE d.employe_id = ? ORDER BY d.date_debut DESC, d.id DESC'
        );
        $requete->execute([$employeId]);
        return $requete->fetchAll();
    }

    /**
     * Demandes qui attendent une action de cet utilisateur (avis ou décision).
     *
     * @return list<array<string, mixed>>
     */
    public function aTraiter(array $utilisateur): array
    {
        $requete = match ($utilisateur['role']) {
            'chef_service' => $this->db->prepare(self::SELECT_DEMANDE
                . " WHERE d.statut = 'en_attente_avis' AND e.manager_id = ? ORDER BY d.date_debut"),
            'directeur' => $this->db->prepare(self::SELECT_DEMANDE
                . " WHERE d.statut = 'en_attente_decision' AND d.employe_id <> ? ORDER BY d.date_debut"),
            'personnel' => $this->db->prepare(self::SELECT_DEMANDE
                . " WHERE d.statut = 'en_attente_decision' AND e.role = 'directeur' AND d.employe_id <> ? ORDER BY d.date_debut"),
            default => null,
        };
        if ($requete === null) {
            return [];
        }
        $requete->execute([(int) $utilisateur['id']]);
        return $requete->fetchAll();
    }

    /** @return list<array<string, mixed>> dernières demandes traitées par cet utilisateur (avis ou décision) */
    public function dernieresDecisions(array $utilisateur, int $limite = 10): array
    {
        $requete = $this->db->prepare(self::SELECT_DEMANDE
            . " WHERE d.valideur_id = ? OR d.avis_par = ? ORDER BY COALESCE(d.traitee_le, d.avis_le) DESC LIMIT $limite");
        $requete->execute([(int) $utilisateur['id'], (int) $utilisateur['id']]);
        return $requete->fetchAll();
    }

    /**
     * Demandes (accordées, et en cours si demandé) qui chevauchent une période, pour des agents donnés.
     *
     * @param list<int> $employeIds
     * @return list<array<string, mixed>>
     */
    public function absences(array $employeIds, string $debut, string $fin, bool $avecEnCours = false): array
    {
        if ($employeIds === []) {
            return [];
        }
        $statuts = $avecEnCours ? "'approuvee', 'en_attente_avis', 'en_attente_decision'" : "'approuvee'";
        $marques = implode(',', array_fill(0, count($employeIds), '?'));
        $requete = $this->db->prepare(
            self::SELECT_DEMANDE . " WHERE d.employe_id IN ($marques) AND d.statut IN ($statuts)
              AND d.date_debut <= ? AND d.date_fin >= ? ORDER BY d.date_debut"
        );
        $requete->execute([...$employeIds, $fin, $debut]);
        return $requete->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function prochainCongeAccorde(int $employeId, DateTimeImmutable $aPartirDe): ?array
    {
        $requete = $this->db->prepare(
            "SELECT * FROM demandes_conges WHERE employe_id = ? AND statut = 'approuvee' AND date_fin >= ?
             ORDER BY date_debut LIMIT 1"
        );
        $requete->execute([$employeId, $aPartirDe->format('Y-m-d')]);
        return $requete->fetch() ?: null;
    }
}
