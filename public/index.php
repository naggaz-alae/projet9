<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

rediriger(Auth::utilisateur(Database::connexion()) ? 'tableau-de-bord.php' : 'connexion.php');
