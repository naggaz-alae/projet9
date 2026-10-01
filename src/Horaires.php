<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/**
 * Horaire de travail d'un jour donné.
 * Horaire normal : configuration (décret n° 2-05-916). Périodes particulières (ramadan…) :
 * table horaires_speciaux, saisie chaque année par le bureau du personnel.
 */
final class Horaires
{
    /** @var list<array{nom: string, date_debut: string, date_fin: string, heure_debut: string, heure_fin: string, pause_minutes: int}>|null */
    private static ?array $speciaux = null;
    private static ?Closure $chargeur = null;

    public static function definirChargeur(?Closure $chargeur): void
    {
        self::$chargeur = $chargeur;
        self::$speciaux = null;
    }

    /** @param list<array<string, mixed>> $speciaux */
    public static function definirSpeciaux(array $speciaux): void
    {
        self::$speciaux = $speciaux;
    }

    /** @return list<array<string, mixed>> */
    private static function speciaux(): array
    {
        if (self::$speciaux === null) {
            self::$speciaux = self::$chargeur !== null ? (self::$chargeur)() : [];
        }
        return self::$speciaux;
    }

    /**
     * Horaire du jour, ou null si le jour n'est pas travaillé (week-end, férié).
     *
     * @return array{debut: string, fin: string, pause: int, periode: ?string}|null
     */
    public static function du(DateTimeInterface $jour): ?array
    {
        if (!Calendrier::estOuvre($jour)) {
            return null;
        }
        $date = $jour->format('Y-m-d');
        foreach (self::speciaux() as $s) {
            if ($date >= $s['date_debut'] && $date <= $s['date_fin']) {
                return ['debut' => (string) $s['heure_debut'], 'fin' => (string) $s['heure_fin'],
                    'pause' => (int) $s['pause_minutes'], 'periode' => (string) $s['nom']];
            }
        }
        $cle = (int) $jour->format('N') === 5 ? 'vendredi' : 'standard';
        $horaire = config("horaires.$cle");
        return ['debut' => (string) $horaire['debut'], 'fin' => (string) $horaire['fin'],
            'pause' => (int) $horaire['pause'], 'periode' => null];
    }

    /** Temps de travail attendu un jour donné, en secondes (0 si jour non travaillé). */
    public static function secondesPrevues(DateTimeInterface $jour): int
    {
        $h = self::du($jour);
        if ($h === null) {
            return 0;
        }
        return max(0, self::minutes($h['fin']) - self::minutes($h['debut']) - $h['pause']) * 60;
    }

    public static function minutes(string $heure): int
    {
        [$h, $m] = array_map('intval', explode(':', $heure) + [1 => 0]);
        return $h * 60 + $m;
    }

    public static function libelle(array $horaire): string
    {
        return sprintf('%s – %s', str_replace(':', 'h', $horaire['debut']), str_replace(':', 'h', $horaire['fin']));
    }
}
