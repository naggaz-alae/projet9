<?php

declare(strict_types=1);

/*
 * Aperçu du nombre de jours ouvrés d'une demande de congé.
 * Le calcul est fait côté serveur : une seule source de vérité (la même fonction que lors de l'enregistrement).
 */

require __DIR__ . '/../../src/bootstrap.php';

Auth::exiger(Database::connexion());

$debut = date_valide(param('debut'));
$fin = date_valide(param('fin'));
if ($debut === null || $fin === null) {
    repondre_json(['ok' => false, 'message' => 'Dates invalides.'], 422);
}

try {
    $jours = Calendrier::joursOuvres($debut, $fin, param('debut_apres_midi') === '1', param('fin_midi') === '1');
} catch (ErreurMetier $e) {
    repondre_json(['ok' => false, 'message' => $e->getMessage()], 422);
}

$feries = [];
foreach (Calendrier::feriesEntre($debut, $fin) as $date => $nom) {
    if ((int) (new DateTimeImmutable($date))->format('N') <= 5) {
        $feries[] = ['date' => $date, 'nom' => $nom];
    }
}
repondre_json(['ok' => true, 'jours' => $jours, 'jours_texte' => nombre($jours), 'feries' => $feries]);
