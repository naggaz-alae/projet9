<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db);
$conges = new Conges($db);
$maintenant = new DateTimeImmutable();

$saisie = ['type' => 'administratif', 'date_debut' => '', 'date_fin' => '', 'motif' => '', 'debut_apres_midi' => false, 'fin_midi' => false];

if (est_post()) {
    Csrf::verifier();
    try {
        if (param('action') === 'annuler') {
            $conges->annuler((int) param_entier('id'), (int) $u['id'], $maintenant);
            flash('succes', 'Demande annulée.');
            rediriger('conges.php');
        }
        $saisie = [
            'type' => param('type'), 'date_debut' => param('date_debut'), 'date_fin' => param('date_fin'),
            'motif' => param('motif'), 'debut_apres_midi' => isset($_POST['debut_apres_midi']), 'fin_midi' => isset($_POST['fin_midi']),
        ];
        $id = $conges->demander((int) $u['id'], $saisie['type'], $saisie['date_debut'], $saisie['date_fin'],
            $saisie['debut_apres_midi'], $saisie['fin_midi'], $saisie['motif'], $maintenant);
        $etape = $conges->demande($id)['statut'] === 'en_attente_avis' ? 'à votre chef de service pour avis' : 'pour décision';
        flash('succes', "Demande transmise $etape.");
        rediriger('conges.php');
    } catch (ErreurMetier $e) {
        flash('erreur', $e->getMessage());
    }
}

$annee = (int) $maintenant->format('Y');
$soldes = $conges->soldes((int) $u['id'], $annee);
$demandes = $conges->demandesDe((int) $u['id']);

entete('Congés', $u, 'conges.php');
?>
<div class="titre-page"><h1>Mes congés</h1></div>

<div class="grille grille-2">
    <section class="carte">
        <h2>Nouvelle demande</h2>
        <form method="post" class="formulaire" data-form-conge>
            <?= Csrf::champ() ?>
            <label>Type de congé
                <select name="type">
                    <?php foreach (Conges::TYPES as $cle => $libelle): ?>
                        <option value="<?= e($cle) ?>"<?= $saisie['type'] === $cle ? ' selected' : '' ?>><?= e($libelle) ?>
                            (<?= e(nombre($soldes[$cle]['disponible'])) ?> j disponibles en <?= $annee ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="ligne-champs">
                <label>Du
                    <input type="date" name="date_debut" value="<?= e($saisie['date_debut']) ?>" min="<?= e($maintenant->format('Y-m-d')) ?>" required>
                </label>
                <label>Au
                    <input type="date" name="date_fin" value="<?= e($saisie['date_fin']) ?>" min="<?= e($maintenant->format('Y-m-d')) ?>" required>
                </label>
            </div>
            <div class="ligne-champs">
                <label class="case"><input type="checkbox" name="debut_apres_midi" value="1"<?= $saisie['debut_apres_midi'] ? ' checked' : '' ?>> Commencer l'après-midi</label>
                <label class="case"><input type="checkbox" name="fin_midi" value="1"<?= $saisie['fin_midi'] ? ' checked' : '' ?>> Terminer à midi</label>
            </div>
            <p class="apercu" data-apercu-jours aria-live="polite"></p>
            <label>Motif <span class="facultatif">(obligatoire pour un congé exceptionnel)</span>
                <input type="text" name="motif" maxlength="255" value="<?= e($saisie['motif']) ?>">
            </label>
            <button type="submit" class="bouton bouton-principal">Envoyer la demande</button>
            <p class="aide">Circuit : avis du chef de service, puis décision du directeur provincial.
                Week-ends et jours fériés ne sont pas décomptés.</p>
        </form>
    </section>

    <section class="carte">
        <h2>Mes droits <?= $annee ?></h2>
        <table class="tableau">
            <thead><tr><th>Type</th><th class="nombre">Droits</th><th class="nombre">Pris</th><th class="nombre">En cours</th><th class="nombre">Disponible</th></tr></thead>
            <tbody>
            <?php foreach ($soldes as $type => $s): ?>
                <tr><th scope="row"><?= e(Conges::TYPES[$type]) ?></th>
                    <td class="nombre"><?= e(nombre($s['acquis'])) ?></td><td class="nombre"><?= e(nombre($s['pris'])) ?></td>
                    <td class="nombre"><?= e(nombre($s['en_attente'])) ?></td><td class="nombre"><strong><?= e(nombre($s['disponible'])) ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <ul class="aide">
            <?php foreach (Conges::AIDE_TYPES as $type => $aide): ?>
                <li><strong><?= e(Conges::TYPES[$type]) ?></strong> : <?= e($aide) ?>.</li>
            <?php endforeach; ?>
            <li>Les droits non pris ne sont reportés qu'à titre exceptionnel, une seule fois (art. 40).</li>
        </ul>
    </section>
</div>

<section class="carte">
    <h2>Historique de mes demandes</h2>
    <?php if ($demandes === []): ?>
        <p class="vide">Aucune demande pour l'instant.</p>
    <?php else: ?>
    <div class="tableau-defilant">
    <table class="tableau">
        <thead><tr><th>Période</th><th>Type</th><th class="nombre">Jours</th><th>Statut</th><th>Avis du chef</th><th>Décision</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($demandes as $d): ?>
            <tr>
                <td><?= e(ucfirst(periode_conge($d))) ?></td>
                <td><?= e(Conges::TYPES[$d['type']]) ?></td>
                <td class="nombre"><?= e(nombre((float) $d['nb_jours'])) ?></td>
                <td><?= pastille_statut((string) $d['statut']) ?></td>
                <td class="petit">
                    <?php if ($d['avis']): ?><?= pastille_avis((string) $d['avis']) ?>
                        <?php if ($d['avis_commentaire']): ?><br><em>« <?= e($d['avis_commentaire']) ?> »</em><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="petit">
                    <?php if ($d['valideur_nom']): ?><?= e($d['valideur_prenom'] . ' ' . $d['valideur_nom']) ?><?php endif; ?>
                    <?php if ($d['commentaire_valideur']): ?><br><em>« <?= e($d['commentaire_valideur']) ?> »</em><?php endif; ?>
                </td>
                <td>
                    <?php if (in_array($d['statut'], Conges::EN_COURS, true)): ?>
                        <form method="post" data-confirmer="Annuler cette demande ?">
                            <?= Csrf::champ() ?>
                            <input type="hidden" name="action" value="annuler">
                            <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                            <button type="submit" class="bouton bouton-discret">Annuler</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php pied();
