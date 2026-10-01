<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

// Déconnexion uniquement en POST avec jeton CSRF : un lien piégé ne peut pas déconnecter quelqu'un.
if (!est_post()) {
    rediriger('tableau-de-bord.php');
}
Csrf::verifier();
Auth::fermerSession();
rediriger('connexion.php');
