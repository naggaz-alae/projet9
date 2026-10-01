<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/** Accès aux pointages en base + calculs par jour et par période. */
final class PointageService
{
    public function __construct(private PDO $db)
    {
    }

    /** @return list<array{type: string, horodatage: string, source: string}> */
    public function evenements(int $employeId, DateTimeImmutable $jour): array
    {
        $debut = $jour->setTime(0, 0);
        return $this->evenementsEntre($employeId, $debut, $debut->modify('+1 day'));
    }

    /** @return list<array{type: string, horodatage: string, source: string}> */
    private function evenementsEntre(int $employeId, DateTimeImmutable $debut, DateTimeImmutable $finExclue): array
    {
        $requete = $this->db->prepare(
            'SELECT type, horodatage, source FROM pointages
             WHERE employe_id = ? AND horodatage >= ? AND horodatage < ?
             ORDER BY horodatage, id'
        );
        $requete->execute([$employeId, $debut->format('Y-m-d H:i:s'), $finExclue->format('Y-m-d H:i:s')]);
        return array_map(fn (array $l) => [
            'type' => (string) $l['type'],
            'horodatage' => substr((string) $l['horodatage'], 0, 19),
            'source' => (string) $l['source'],
        ], $requete->fetchAll());
    }

    /** Enregistre un badge après vérification de la règle de transition. Renvoie le nouvel état. */
    public function badger(int $employeId, string $type, string $source, DateTimeImmutable $maintenant): string
    {
        $etat = Pointage::etat($this->evenements($employeId, $maintenant));
        $nouvelEtat = Pointage::suivant($etat, $type);

        $this->db->prepare('INSERT INTO pointages (employe_id, type, horodatage, source) VALUES (?, ?, ?, ?)')
            ->execute([$employeId, $type, $maintenant->format('Y-m-d H:i:s'), $source]);
        return $nouvelEtat;
    }

    /**
     * Une ligne par jour entre $debut et $fin (inclus).
     *
     * @return list<array{jour: DateTimeImmutable, evenements: list<array{type: string, horodatage: string, source: string}>,
     *                    secondes: int, anomalies: list<string>, prevu: int, ferie: ?string}>
     */
    public function periode(int $employeId, DateTimeImmutable $debut, DateTimeImmutable $fin, DateTimeImmutable $maintenant): array
    {
        $debut = $debut->setTime(0, 0);
        $fin = $fin->setTime(0, 0);
        $parJour = [];
        foreach ($this->evenementsEntre($employeId, $debut, $fin->modify('+1 day')) as $evenement) {
            $parJour[substr($evenement['horodatage'], 0, 10)][] = $evenement;
        }

        $aujourdhui = aujourdhui($maintenant);
        $jours = [];
        for ($jour = $debut; $jour <= $fin; $jour = $jour->modify('+1 day')) {
            $evenements = $parJour[$jour->format('Y-m-d')] ?? [];
            $estAujourdhui = $jour == $aujourdhui;
            $jours[] = [
                'jour' => $jour,
                'evenements' => $evenements,
                'secondes' => Pointage::secondesTravaillees($evenements, $estAujourdhui ? $maintenant : null),
                'anomalies' => Pointage::anomalies($evenements, $jour < $aujourdhui, Horaires::du($jour),
                    (int) config('horaires.tolerance_minutes', 5)),
                'prevu' => Horaires::secondesPrevues($jour),
                'ferie' => Calendrier::ferie($jour),
            ];
        }
        return $jours;
    }

    /**
     * Situation du jour pour une liste d'employés (vue « qui est là ? »).
     *
     * @param list<array<string, mixed>> $employes
     * @return list<array{employe: array<string, mixed>, etat: string, secondes: int, arrivee: ?string}>
     */
    public function situationDuJour(array $employes, DateTimeImmutable $maintenant): array
    {
        if ($employes === []) {
            return [];
        }
        $ids = array_map(fn (array $e) => (int) $e['id'], $employes);
        $marques = implode(',', array_fill(0, count($ids), '?'));
        $jour = aujourdhui($maintenant);
        $requete = $this->db->prepare(
            "SELECT employe_id, type, horodatage FROM pointages
             WHERE employe_id IN ($marques) AND horodatage >= ? AND horodatage < ?
             ORDER BY horodatage, id"
        );
        $requete->execute([...$ids, $jour->format('Y-m-d H:i:s'), $jour->modify('+1 day')->format('Y-m-d H:i:s')]);

        $parEmploye = [];
        foreach ($requete->fetchAll() as $ligne) {
            $parEmploye[(int) $ligne['employe_id']][] = [
                'type' => (string) $ligne['type'],
                'horodatage' => substr((string) $ligne['horodatage'], 0, 19),
            ];
        }

        $resultat = [];
        foreach ($employes as $employe) {
            $evenements = $parEmploye[(int) $employe['id']] ?? [];
            $premiere = array_values(array_filter($evenements, fn (array $e) => $e['type'] === 'entree'))[0] ?? null;
            $resultat[] = [
                'employe' => $employe,
                'etat' => Pointage::etat($evenements),
                'secondes' => Pointage::secondesTravaillees($evenements, $maintenant),
                'arrivee' => $premiere ? heure($premiere['horodatage']) : null,
            ];
        }
        return $resultat;
    }
}
