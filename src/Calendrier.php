<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/**
 * Jours fériés au Maroc et calcul des jours de congé décomptés.
 *
 * - Fêtes civiles : dates fixes, calculées ici.
 * - Fêtes religieuses (Aïd al-Fitr, Aïd al-Adha, 1er Moharram, Aïd al-Mawlid) : elles suivent le
 *   calendrier lunaire et sont annoncées chaque année après observation du croissant.
 *   Elles ne peuvent pas être calculées à l'avance : le bureau du personnel les saisit
 *   (table feries_variables), et elles sont chargées ici à la première utilisation.
 */
final class Calendrier
{
    /** @var array<string, string>|null date => nom des fêtes religieuses connues */
    private static ?array $variables = null;
    private static ?Closure $chargeur = null;

    /** Fournit la fonction qui lit les fêtes religieuses en base (appelée une seule fois, à la demande). */
    public static function definirChargeur(?Closure $chargeur): void
    {
        self::$chargeur = $chargeur;
        self::$variables = null;
    }

    /** @param array<string, string> $feries date 'Y-m-d' => nom (utilisé par les tests) */
    public static function definirFeriesVariables(array $feries): void
    {
        self::$variables = $feries;
    }

    /** @return array<string, string> */
    private static function variables(): array
    {
        if (self::$variables === null) {
            self::$variables = self::$chargeur !== null ? (self::$chargeur)() : [];
        }
        return self::$variables;
    }

    /** @return array<string, string> fêtes civiles à date fixe d'une année */
    public static function feriesFixes(int $annee): array
    {
        $feries = [
            "$annee-01-01" => 'Nouvel an',
            "$annee-01-11" => "Manifeste de l'Indépendance",
            "$annee-05-01" => 'Fête du Travail',
            "$annee-07-30" => 'Fête du Trône',
            "$annee-08-14" => 'Allégeance de Oued Eddahab',
            "$annee-08-20" => 'Révolution du Roi et du Peuple',
            "$annee-08-21" => 'Fête de la Jeunesse',
            "$annee-11-06" => 'Marche Verte',
            "$annee-11-18" => "Fête de l'Indépendance",
        ];
        if ($annee >= 2024) {
            $feries["$annee-01-14"] = 'Nouvel an amazigh';
        }
        if ($annee >= 2026) {
            $feries["$annee-10-31"] = "Fête de l'Unité";
        }
        ksort($feries);
        return $feries;
    }

    /** @return array<string, string> tous les fériés connus d'une année (fixes + religieux saisis) */
    public static function feries(int $annee): array
    {
        $feries = self::feriesFixes($annee);
        foreach (self::variables() as $date => $nom) {
            if (str_starts_with($date, (string) $annee)) {
                $feries[$date] = $nom;
            }
        }
        ksort($feries);
        return $feries;
    }

    public static function ferie(DateTimeInterface $jour): ?string
    {
        $date = $jour->format('Y-m-d');
        return self::variables()[$date] ?? self::feriesFixes((int) $jour->format('Y'))[$date] ?? null;
    }

    /** Jour travaillé dans l'administration : du lundi au vendredi, hors jours fériés. */
    public static function estOuvre(DateTimeInterface $jour): bool
    {
        return (int) $jour->format('N') <= 5 && self::ferie($jour) === null;
    }

    /**
     * Nombre de jours décomptés pour un congé : jours travaillés de l'administration
     * (lundi au vendredi, hors fériés). Demi-journées : début l'après-midi et/ou fin à midi.
     */
    public static function joursOuvres(
        DateTimeImmutable $debut,
        DateTimeImmutable $fin,
        bool $debutApresMidi = false,
        bool $finMidi = false,
    ): float {
        $debut = $debut->setTime(0, 0);
        $fin = $fin->setTime(0, 0);
        if ($fin < $debut) {
            throw new ErreurMetier('La date de fin doit être identique ou postérieure à la date de début.');
        }
        if ($debut == $fin && $debutApresMidi && $finMidi) {
            throw new ErreurMetier("Un congé d'une journée ne peut pas commencer l'après-midi et finir à midi.");
        }

        $total = 0.0;
        for ($jour = $debut; $jour <= $fin; $jour = $jour->modify('+1 day')) {
            if (self::estOuvre($jour)) {
                $total += 1;
            }
        }
        if ($debutApresMidi && self::estOuvre($debut)) {
            $total -= 0.5;
        }
        if ($finMidi && self::estOuvre($fin)) {
            $total -= 0.5;
        }
        return $total;
    }

    /** @return array<string, string> jours fériés compris entre deux dates */
    public static function feriesEntre(DateTimeImmutable $debut, DateTimeImmutable $fin): array
    {
        $resultat = [];
        for ($annee = (int) $debut->format('Y'); $annee <= (int) $fin->format('Y'); $annee++) {
            foreach (self::feries($annee) as $date => $nom) {
                if ($date >= $debut->format('Y-m-d') && $date <= $fin->format('Y-m-d')) {
                    $resultat[$date] = $nom;
                }
            }
        }
        return $resultat;
    }

    /** n-ième jour travaillé après (n > 0) ou avant (n < 0) une date. */
    public static function decalerJoursOuvres(DateTimeImmutable $depart, int $n): DateTimeImmutable
    {
        $pas = $n >= 0 ? '+1 day' : '-1 day';
        $jour = $depart->setTime(0, 0);
        for ($restants = abs($n); $restants > 0;) {
            $jour = $jour->modify($pas);
            if (self::estOuvre($jour)) {
                $restants--;
            }
        }
        return $jour;
    }
}
