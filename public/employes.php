<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$db = Database::connexion();
$u = Auth::exiger($db, 'personnel');
$employes = new Employes($db);
$conges = new Conges($db);
$annee = (int) (new DateTimeImmutable())->format('Y');

$idEdition = param_entier('id');
$fiche = $idEdition !== null ? $employes->parId($idEdition) : null;
if ($idEdition !== null && $fiche === null) {
    flash('erreur', 'Agent introuvable.');
    rediriger('employes.php');
}

$saisie = $fiche ?? ['matricule' => '', 'prenom' => '', 'nom' => '', 'email' => '', 'role' => 'fonctionnaire',
    'manager_id' => null, 'service' => '', 'date_entree' => '', 'actif' => 1];
$droits = [];
foreach (array_keys(Conges::TYPES) as $type) {
    $droits[$type] = $fiche ? $conges->droits((int) $fiche['id'], $type, $annee) : (float) config("droits_annuels.$type");
}

if (est_post()) {
    Csrf::verifier();
    $donnees = array_map(fn ($v) => is_string($v) ? $v : '', $_POST);
    try {
        if ($fiche !== null && (int) $fiche['id'] === (int) $u['id'] && (!isset($donnees['actif']) || ($donnees['role'] ?? '') !== 'personnel')) {
            throw new ErreurMetier('Vous ne pouvez pas désactiver votre propre compte ni retirer votre rôle.');
        }
        $id = $employes->enregistrer($donnees, $fiche ? (int) $fiche['id'] : null, new DateTimeImmutable());
        flash('succes', $fiche ? 'Fiche mise à jour.' : 'Agent créé.');
        rediriger('employes.php?id=' . $id);
    } catch (ErreurMetier $e) {
        flash('erreur', $e->getMessage());
        $saisie = array_merge($saisie, $donnees, ['actif' => isset($donnees['actif']) ? 1 : 0]);
        foreach (array_keys(Conges::TYPES) as $type) {
            $droits[$type] = $donnees["droits_$type"] ?? $droits[$type];
        }
    }
}

$liste = $employes->tous(false);
$responsables = $employes->responsables();
$noms = [];
foreach ($liste as $e) {
    $noms[(int) $e['id']] = Employes::nomComplet($e);
}

entete('Agents', $u, 'employes.php');
?>
<div class="titre-page">
    <h1>Agents <span class="compteur"><?= count($liste) ?></span></h1>
    <a class="bouton bouton-principal" href="<?= e(url('employes.php')) ?>">+ Nouvel agent</a>
</div>

<div class="grille grille-liste">
    <section class="carte">
        <div class="tableau-defilant">
        <table class="tableau">
            <thead><tr><th>Matricule</th><th>Nom</th><th>Fonction</th><th>Service</th><th>Supérieur</th><th>Entrée</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($liste as $e): ?>
                <tr class="<?= (int) $e['actif'] ? '' : 'attenue' ?><?= $fiche && (int) $fiche['id'] === (int) $e['id'] ? ' selection' : '' ?>">
                    <td><code><?= e($e['matricule']) ?></code></td>
                    <th scope="row"><?= e(Employes::nomComplet($e)) ?><?= (int) $e['actif'] ? '' : ' <small>(inactif)</small>' ?></th>
                    <td><?= e(Employes::ROLES[$e['role']]) ?></td>
                    <td><?= e($e['service'] ?? '') ?></td>
                    <td><?= e($e['manager_id'] !== null ? ($noms[(int) $e['manager_id']] ?? '—') : '—') ?></td>
                    <td class="petit"><?= $e['date_entree'] ? e(date_courte((string) $e['date_entree'])) : '—' ?></td>
                    <td><a href="<?= e(url('employes.php?id=' . (int) $e['id'])) ?>">Modifier</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="carte">
        <h2><?= $fiche ? 'Modifier ' . e(Employes::nomComplet($fiche)) : 'Nouvel agent' ?></h2>
        <form method="post" class="formulaire" autocomplete="off">
            <?= Csrf::champ() ?>
            <div class="ligne-champs">
                <label>Prénom <input name="prenom" value="<?= e($saisie['prenom']) ?>" required></label>
                <label>Nom <input name="nom" value="<?= e($saisie['nom']) ?>" required></label>
            </div>
            <div class="ligne-champs">
                <label>Matricule <input name="matricule" value="<?= e($saisie['matricule']) ?>" required pattern="[A-Za-z0-9-]{2,20}"></label>
                <label>E-mail <input type="email" name="email" value="<?= e($saisie['email']) ?>" required></label>
            </div>
            <div class="ligne-champs">
                <label>Fonction
                    <select name="role">
                        <?php foreach (Employes::ROLES as $cle => $libelle): ?>
                            <option value="<?= e($cle) ?>"<?= $saisie['role'] === $cle ? ' selected' : '' ?>><?= e($libelle) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Supérieur hiérarchique
                    <select name="manager_id">
                        <option value="">— Aucun —</option>
                        <?php foreach ($responsables as $r): if ($fiche && (int) $r['id'] === (int) $fiche['id']) continue; ?>
                            <option value="<?= (int) $r['id'] ?>"<?= (int) $saisie['manager_id'] === (int) $r['id'] ? ' selected' : '' ?>><?= e(Employes::nomComplet($r)) ?> (<?= e(Employes::ROLES[$r['role']]) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="ligne-champs">
                <label>Service <input name="service" value="<?= e($saisie['service'] ?? '') ?>"></label>
                <label>Entrée en fonction <input type="date" name="date_entree" value="<?= e((string) ($saisie['date_entree'] ?? '')) ?>"></label>
            </div>
            <fieldset>
                <legend>Droits <?= $annee ?> (jours)</legend>
                <div class="ligne-champs">
                    <?php foreach (Conges::TYPES as $type => $libelle): ?>
                        <label><?= e($libelle) ?> <input type="number" name="droits_<?= e($type) ?>" min="0" max="60" step="0.5" value="<?= e((string) $droits[$type]) ?>"></label>
                    <?php endforeach; ?>
                </div>
                <p class="aide">Par défaut : 22 jours de congé administratif et 10 jours exceptionnels. Ajouter ici un éventuel report exceptionnel.</p>
            </fieldset>
            <div class="ligne-champs">
                <label>Mot de passe <?= $fiche ? '<span class="facultatif">(laisser vide pour ne pas changer)</span>' : '' ?>
                    <input type="password" name="mot_de_passe" minlength="8" autocomplete="new-password"<?= $fiche ? '' : ' required' ?>></label>
                <label>Code PIN borne <span class="facultatif">(4 à 6 chiffres)</span>
                    <input type="password" name="pin" inputmode="numeric" pattern="\d{4,6}" autocomplete="off"></label>
            </div>
            <?php if ($fiche): ?>
                <label class="case"><input type="checkbox" name="actif" value="1"<?= (int) $saisie['actif'] ? ' checked' : '' ?>> Compte actif</label>
            <?php endif; ?>
            <button type="submit" class="bouton bouton-principal"><?= $fiche ? 'Enregistrer' : 'Créer l\'agent' ?></button>
        </form>
    </section>
</div>
<?php pied();
