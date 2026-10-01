<?php

/*
 * Temps & Congés — badgeage et gestion des congés
 * Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021.
 * Projet personnel repris en 2026, non officiel ; données de démonstration fictives.
 */

declare(strict_types=1);

/** Gabarit commun des pages : en-tête, navigation, messages, pied de page. */

/** @param array<string, mixed>|null $utilisateur */
function entete(string $titre, ?array $utilisateur = null, string $actif = '', string $classeBody = ''): void
{
    $liens = [];
    if ($utilisateur !== null) {
        $liens = ['tableau-de-bord.php' => 'Tableau de bord', 'pointages.php' => 'Pointages', 'conges.php' => 'Congés',
            'equipe.php' => 'Présences'];
        if ($utilisateur['role'] !== 'fonctionnaire') {
            $liens['validation.php'] = 'Validation';
            $liens['export.php'] = 'Export';
        }
        if ($utilisateur['role'] === 'personnel') {
            $liens['employes.php'] = 'Agents';
            $liens['calendrier.php'] = 'Calendrier';
        }
    }
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre) ?> · <?= e(config('nom_app')) ?></title>
    <meta name="description" content="Badgeage et gestion des congés">
    <meta name="theme-color" content="#0f6b5c">
    <meta name="base-url" content="<?= e(url()) ?>">
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(url('assets/icons/icone.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(url('assets/icons/icone-192.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
    <script src="<?= e(url('assets/js/app.js')) ?>" defer></script>
</head>
<body class="<?= e($classeBody) ?>">
<?php if ($utilisateur !== null): ?>
<header class="barre">
    <a class="marque" href="<?= e(url('tableau-de-bord.php')) ?>">
        <img src="<?= e(url('assets/icons/icone.svg')) ?>" alt="" width="28" height="28">
        <span><?= e(config('nom_app')) ?></span>
    </a>
    <nav class="navigation" aria-label="Navigation principale">
        <?php foreach ($liens as $lien => $libelle): ?>
            <a href="<?= e(url($lien)) ?>"<?= $lien === $actif ? ' aria-current="page"' : '' ?>><?= e($libelle) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="session">
        <span class="session-nom"><?= e(Employes::nomComplet($utilisateur)) ?>
            <small><?= e(Employes::ROLES[$utilisateur['role']]) ?></small></span>
        <form method="post" action="<?= e(url('deconnexion.php')) ?>">
            <?= Csrf::champ() ?>
            <button type="submit" class="bouton bouton-discret">Déconnexion</button>
        </form>
    </div>
</header>
<?php endif; ?>
<main class="contenu" id="contenu">
<?php foreach (flashs() as $message): ?>
    <div class="alerte alerte-<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></div>
<?php endforeach;
}

function pied(): void
{
    ?>
</main>
<footer class="pied">
    <?= e(config('nom_app')) ?> · <?= e(config('contexte')) ?><br>
    Projet personnel, non officiel · données de démonstration fictives
</footer>
</body>
</html>
<?php
}

function pastille_statut(string $statut): string
{
    return '<span class="pastille pastille-' . e($statut) . '">' . e(Conges::STATUTS[$statut] ?? $statut) . '</span>';
}

function pastille_etat(string $etat): string
{
    return '<span class="pastille etat-' . e($etat) . '">' . e(Pointage::ETATS[$etat] ?? $etat) . '</span>';
}

/** Période lisible d'une demande de congé, demi-journées comprises. */
function periode_conge(array $demande): string
{
    $apresMidi = (bool) (int) $demande['debut_apres_midi'];
    $midi = (bool) (int) $demande['fin_midi'];
    if ($demande['date_debut'] === $demande['date_fin']) {
        $moment = $apresMidi ? ' (après-midi)' : ($midi ? ' (matin)' : '');
        return 'le ' . date_courte((string) $demande['date_debut']) . $moment;
    }
    return sprintf('du %s%s au %s%s',
        date_courte((string) $demande['date_debut']), $apresMidi ? ' (après-midi)' : '',
        date_courte((string) $demande['date_fin']), $midi ? ' (midi)' : '');
}

function pastille_avis(string $avis): string
{
    return '<span class="pastille pastille-' . e($avis) . '">Avis ' . ($avis === 'favorable' ? 'favorable' : 'défavorable') . '</span>';
}
