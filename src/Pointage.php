<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/**
 * Règles du badgeage : une machine à états.
 *
 *   hors ──arrivée──► présent ──début de pause──► pause
 *    ▲                  │  ▲                        │
 *    └─────départ───────┘  └──────fin de pause──────┘   (départ possible aussi depuis la pause)
 *
 * On ne peut donc pas badger deux arrivées de suite, ni une pause sans être arrivé.
 */
final class Pointage
{
    public const HORS = 'hors';
    public const PRESENT = 'present';
    public const PAUSE = 'pause';

    public const LIBELLES = [
        'entree' => 'Arrivée',
        'debut_pause' => 'Début de pause',
        'fin_pause' => 'Fin de pause',
        'sortie' => 'Départ',
    ];

    public const ETATS = [
        self::HORS => 'Non badgé',
        self::PRESENT => 'Au travail',
        self::PAUSE => 'En pause',
    ];

    private const TRANSITIONS = [
        self::HORS => ['entree' => self::PRESENT],
        self::PRESENT => ['debut_pause' => self::PAUSE, 'sortie' => self::HORS],
        self::PAUSE => ['fin_pause' => self::PRESENT, 'sortie' => self::HORS],
    ];

    public static function suivant(string $etat, string $type): string
    {
        if (!isset(self::LIBELLES[$type])) {
            throw new ErreurMetier('Type de badge inconnu.');
        }
        $cible = self::TRANSITIONS[$etat][$type] ?? null;
        if ($cible === null) {
            throw new ErreurMetier(sprintf(
                'Badge « %s » impossible : votre statut actuel est « %s ».',
                self::LIBELLES[$type],
                self::ETATS[$etat],
            ));
        }
        return $cible;
    }

    /**
     * État après une liste d'événements triés.
     * Les événements incohérents (données importées ou corrigées) sont ignorés au lieu de bloquer.
     *
     * @param list<array{type: string, horodatage: string}> $evenements
     */
    public static function etat(array $evenements): string
    {
        $etat = self::HORS;
        foreach ($evenements as $evenement) {
            $etat = self::TRANSITIONS[$etat][$evenement['type']] ?? $etat;
        }
        return $etat;
    }

    /** @return list<string> */
    public static function actionsPossibles(string $etat): array
    {
        return array_keys(self::TRANSITIONS[$etat] ?? []);
    }

    /**
     * Temps de travail effectif (pauses exclues), en secondes.
     * Si la personne est encore au travail, la période en cours compte jusqu'à $maintenant ;
     * sans $maintenant (journée passée), une période non refermée ne compte pas.
     *
     * @param list<array{type: string, horodatage: string}> $evenements
     */
    public static function secondesTravaillees(array $evenements, ?DateTimeImmutable $maintenant = null): int
    {
        $total = 0;
        $etat = self::HORS;
        $debutPeriode = null;

        foreach ($evenements as $evenement) {
            $nouvelEtat = self::TRANSITIONS[$etat][$evenement['type']] ?? null;
            if ($nouvelEtat === null) {
                continue;
            }
            $instant = new DateTimeImmutable($evenement['horodatage']);
            if ($etat === self::PRESENT && $debutPeriode !== null) {
                $total += $instant->getTimestamp() - $debutPeriode->getTimestamp();
            }
            $debutPeriode = $nouvelEtat === self::PRESENT ? $instant : null;
            $etat = $nouvelEtat;
        }

        if ($etat === self::PRESENT && $debutPeriode !== null && $maintenant !== null) {
            $total += max(0, $maintenant->getTimestamp() - $debutPeriode->getTimestamp());
        }
        return $total;
    }

    /**
     * Contrôles d'assiduité d'une journée, par rapport à l'horaire officiel.
     *
     * @param list<array{type: string, horodatage: string}> $evenements
     * @param array{debut: string, fin: string}|null $horaire null = jour non travaillé
     * @return list<string>
     */
    public static function anomalies(array $evenements, bool $journeeTerminee, ?array $horaire, int $toleranceMinutes = 5): array
    {
        $anomalies = [];
        if ($evenements === []) {
            return $anomalies;
        }
        $etatFinal = self::etat($evenements);
        if ($journeeTerminee && $etatFinal !== self::HORS) {
            $anomalies[] = 'Badge de départ manquant';
        }
        if ($horaire === null) {
            return $anomalies;
        }

        $arrivees = array_values(array_filter($evenements, fn (array $e) => $e['type'] === 'entree'));
        if ($arrivees !== []) {
            $heureArrivee = heure($arrivees[0]['horodatage']);
            if (Horaires::minutes($heureArrivee) > Horaires::minutes($horaire['debut']) + $toleranceMinutes) {
                $anomalies[] = "Retard : arrivée à $heureArrivee";
            }
        }

        $departs = array_values(array_filter($evenements, fn (array $e) => $e['type'] === 'sortie'));
        if ($journeeTerminee && $etatFinal === self::HORS && $departs !== []) {
            $heureDepart = heure(end($departs)['horodatage']);
            if (Horaires::minutes($heureDepart) < Horaires::minutes($horaire['fin']) - $toleranceMinutes) {
                $anomalies[] = "Départ anticipé : $heureDepart";
            }
        }
        return $anomalies;
    }
}
