<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db, 'chef_service', 'directeur', 'personnel');
$conges = new Conges($db);

if (est_post()) {
    Csrf::verifier();
    $positif = param('decision') === 'oui';
    $maintenant = new DateTimeImmutable();
    try {
        $demande = $conges->demande((int) param_entier('id')) ?? throw new ErreurMetier('Demande introuvable.');
        if ($demande['statut'] === 'en_attente_avis') {
            $conges->donnerAvis((int) $demande['id'], $u, $positif, param('commentaire'), $maintenant);
            flash('succes', 'Avis ' . ($positif ? 'favorable' : 'défavorable') . ' transmis au directeur provincial.');
        } else {
            $conges->decider((int) $demande['id'], $u, $positif, param('commentaire'), $maintenant);
            flash('succes', $positif ? 'Congé accordé.' : 'Congé refusé.');
        }
    } catch (ErreurMetier $e) {
        flash('erreur', $e->getMessage());
    }
    rediriger('validation.php');
}

$demandes = $conges->aTraiter($u);
$decisions = $conges->dernieresDecisions($u);
$perimetre = array_map(fn (array $e) => (int) $e['id'], (new Employes($db))->perimetre($u));

$titre = match ($u['role']) {
    'chef_service' => 'Avis à donner',
    'personnel' => 'Congés du directeur à enregistrer',
    default => 'Décisions à prendre',
};

entete('Validation des congés', $u, 'validation.php');
?>
<div class="titre-page">
    <h1><?= e($titre) ?> <span class="compteur"><?= count($demandes) ?></span></h1>
</div>

<?php if ($demandes === []): ?>
    <section class="carte vide"><p>Aucune demande en attente de votre part.</p></section>
<?php endif; ?>

<div class="liste-demandes">
<?php foreach ($demandes as $d):
    $annee = (int) substr((string) $d['date_debut'], 0, 4);
    $solde = $conges->soldes((int) $d['employe_id'], $annee, (int) $d['id'])[$d['type']];
    $autres = array_filter(
        $conges->absences($perimetre, (string) $d['date_debut'], (string) $d['date_fin'], true),
        fn (array $a) => (int) $a['id'] !== (int) $d['id'] && (int) $a['employe_id'] !== (int) $d['employe_id'],
    );
    $avisAttendu = $d['statut'] === 'en_attente_avis'; ?>
    <article class="carte demande">
        <header>
            <h2><?= e($d['prenom'] . ' ' . $d['nom']) ?> <small><?= e($d['service'] ?? '') ?></small></h2>
            <span class="pastille pastille-en_attente"><?= e(Conges::TYPES[$d['type']]) ?> · <?= e(nombre((float) $d['nb_jours'])) ?> j</span>
        </header>
        <ol class="etapes" aria-label="Circuit de validation">
            <li class="faite">Dépôt</li>
            <li class="<?= $avisAttendu ? 'courante' : 'faite' ?>">Avis du chef</li>
            <li class="<?= $avisAttendu ? '' : 'courante' ?>">Décision</li>
        </ol>
        <p class="demande-periode"><?= e(ucfirst(periode_conge($d))) ?></p>
        <?php if ($d['motif']): ?><p class="petit">Motif : <?= e($d['motif']) ?></p><?php endif; ?>

        <?php if ($d['avis']): ?>
            <div class="avis"><?= pastille_avis((string) $d['avis']) ?> de <?= e($d['avis_prenom'] . ' ' . $d['avis_nom']) ?>
                <?php if ($d['avis_commentaire']): ?><p>« <?= e($d['avis_commentaire']) ?> »</p><?php endif; ?></div>
        <?php elseif (!$avisAttendu): ?>
            <div class="avis">Pas d'avis préalable : demande d'un responsable, transmise directement.</div>
        <?php endif; ?>

        <ul class="points-cles">
            <li class="<?= $solde['disponible'] >= (float) $d['nb_jours'] ? 'ok' : 'ko' ?>">
                Droits <?= $annee ?> disponibles : <?= e(nombre($solde['disponible'])) ?> j
                (après ce congé : <?= e(nombre($solde['disponible'] - (float) $d['nb_jours'])) ?> j)</li>
            <li class="<?= $autres === [] ? 'ok' : 'attention' ?>">
                <?php if ($autres === []): ?>Aucune autre absence sur cette période
                <?php else: ?>Également absent(s) : <?= e(implode(', ', array_map(
                    fn (array $a) => $a['prenom'] . ' ' . $a['nom'] . ($a['statut'] !== 'approuvee' ? ' (en cours)' : ''), $autres))) ?>
                <?php endif; ?></li>
            <li>Déposée le <?= e(date_courte((string) $d['cree_le'])) ?></li>
        </ul>
        <form method="post" class="formulaire decision">
            <?= Csrf::champ() ?>
            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
            <label>Commentaire <span class="facultatif">(obligatoire si <?= $avisAttendu ? 'défavorable' : 'refus' ?>)</span>
                <input type="text" name="commentaire" maxlength="255">
            </label>
            <div class="boutons">
                <button type="submit" name="decision" value="oui" class="bouton bouton-principal"><?= $avisAttendu ? 'Avis favorable' : 'Accorder' ?></button>
                <button type="submit" name="decision" value="non" class="bouton bouton-danger"><?= $avisAttendu ? 'Avis défavorable' : 'Refuser' ?></button>
            </div>
        </form>
    </article>
<?php endforeach; ?>
</div>

<?php if ($decisions): ?>
<section class="carte">
    <h2>Mes derniers avis et décisions</h2>
    <div class="tableau-defilant">
    <table class="tableau">
        <thead><tr><th>Agent</th><th>Période</th><th>Type</th><th>Avis</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach ($decisions as $d): ?>
            <tr><td><?= e($d['prenom'] . ' ' . $d['nom']) ?></td><td><?= e(periode_conge($d)) ?></td>
                <td><?= e(Conges::TYPES[$d['type']]) ?></td>
                <td><?= $d['avis'] ? pastille_avis((string) $d['avis']) : '—' ?></td>
                <td><?= pastille_statut((string) $d['statut']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php endif; ?>
<?php pied();
