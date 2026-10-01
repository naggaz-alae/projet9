<?php

declare(strict_types=1);

/*
 * Enregistre un badge.
 * - Appelé en JavaScript (Accept: application/json) : répond en JSON, la page se met à jour sans rechargement.
 * - Sans JavaScript : simple formulaire, redirection vers le tableau de bord (amélioration progressive).
 */

require __DIR__ . '/../../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db);

if (!est_post()) {
    repondre_json(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
}
Csrf::verifier();

$type = param('type');
$maintenant = new DateTimeImmutable();
$service = new PointageService($db);

try {
    $service->badger((int) $u['id'], $type, 'web', $maintenant);
    $ok = true;
    $message = sprintf('Badge « %s » enregistré à %s.', Pointage::LIBELLES[$type], $maintenant->format('H:i'));
} catch (ErreurMetier $e) {
    $ok = false;
    $message = $e->getMessage();
}

if (!veut_json()) {
    flash($ok ? 'succes' : 'erreur', $message);
    rediriger('tableau-de-bord.php');
}

$evenements = $service->evenements((int) $u['id'], $maintenant);
$etat = Pointage::etat($evenements);
repondre_json([
    'ok' => $ok,
    'message' => $message,
    'etat' => $etat,
    'etat_libelle' => Pointage::ETATS[$etat],
    'actions' => array_map(fn (string $a) => ['type' => $a, 'libelle' => Pointage::LIBELLES[$a]], Pointage::actionsPossibles($etat)),
    'evenements' => array_map(fn (array $ev) => ['heure' => heure($ev['horodatage']), 'libelle' => Pointage::LIBELLES[$ev['type']]], $evenements),
    'secondes' => Pointage::secondesTravaillees($evenements, $maintenant),
    'en_cours' => $etat === Pointage::PRESENT,
], $ok ? 200 : 409);
