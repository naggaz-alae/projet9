#!/usr/bin/env bash
# Test de bout en bout : vrai serveur PHP, vraies requêtes HTTP, parcours complet.
# Lancement : bash tests/fumee.sh
set -eu   # sans pipefail : « curl | grep -q » interromprait curl et ferait échouer le test à tort
cd "$(dirname "$0")/.."

PORT=8799
URL="http://127.0.0.1:$PORT"
TMP=$(mktemp -d)
export DB_DSN="sqlite:$TMP/fumee.sqlite"

php database/installer.php --demo > /dev/null
php -S "127.0.0.1:$PORT" -t public > "$TMP/serveur.log" 2>&1 &
SERVEUR=$!
trap 'kill $SERVEUR 2>/dev/null; rm -rf "$TMP"' EXIT
for _ in $(seq 20); do curl -s -o /dev/null "$URL/connexion.php" && break; sleep 0.2; done

REUSSIS=0
ok()    { REUSSIS=$((REUSSIS + 1)); echo "  ✓ $1"; }
echec() { echo "  ✗ $1"; echo "--- journal du serveur ---"; cat "$TMP/serveur.log"; exit 1; }
jeton() { grep -o 'name="_csrf" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"$//'; }

# connexion <fichier-cookies> <email>
connexion() {
    curl -s -c "$1" -b "$1" "$URL/connexion.php" -o "$TMP/page.html"
    curl -s -c "$1" -b "$1" -o /dev/null -w '%{http_code}' \
        --data-urlencode "_csrf=$(jeton "$TMP/page.html")" --data-urlencode "email=$2" \
        --data-urlencode "mot_de_passe=demo1234" "$URL/connexion.php"
}

AGENT="$TMP/agent.txt"; CHEF="$TMP/chef.txt"; DIR="$TMP/dir.txt"; RH="$TMP/rh.txt"

# --- Accès ---
[ "$(curl -s -o /dev/null -w '%{http_code}' "$URL/tableau-de-bord.php")" = 302 ] && ok "page protégée sans connexion → redirection" || echec "page protégée accessible"
curl -s -c "$TMP/x.txt" "$URL/connexion.php" -o "$TMP/page.html"
curl -s -b "$TMP/x.txt" -c "$TMP/x.txt" --data-urlencode "_csrf=$(jeton "$TMP/page.html")" -d "email=fonctionnaire@demo.test&mot_de_passe=faux" "$URL/connexion.php" | grep -q "Identifiants incorrects" \
    && ok "mauvais mot de passe refusé" || echec "mauvais mot de passe accepté"
[ "$(connexion "$AGENT" fonctionnaire@demo.test)" = 302 ] && ok "connexion fonctionnaire" || echec "connexion fonctionnaire"
curl -s -b "$AGENT" "$URL/tableau-de-bord.php" | grep -q "Bonjour Youssef" && ok "tableau de bord affiché" || echec "tableau de bord"

# --- Badgeage via l'API JSON (Salma n'a pas de badge du jour dans les données de démo) ---
SALMA="$TMP/salma.txt"
connexion "$SALMA" salma.chraibi@demo.test > /dev/null
curl -s -b "$SALMA" "$URL/tableau-de-bord.php" -o "$TMP/tdb.html"
T=$(jeton "$TMP/tdb.html")
R=$(curl -s -b "$SALMA" -H 'Accept: application/json' --data-urlencode "_csrf=$T" -d "type=entree" "$URL/api/badger.php")
echo "$R" | grep -q '"ok":true' && echo "$R" | grep -q '"etat":"present"' && ok "badge d'arrivée (API JSON)" || echec "badge d'arrivée : $R"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$SALMA" -H 'Accept: application/json' --data-urlencode "_csrf=$T" -d "type=entree" "$URL/api/badger.php")
[ "$CODE" = 409 ] && ok "double arrivée refusée (409)" || echec "double arrivée : $CODE"
R=$(curl -s -b "$SALMA" -H 'Accept: application/json' --data-urlencode "_csrf=$T" -d "type=debut_pause" "$URL/api/badger.php")
echo "$R" | grep -q '"etat":"pause"' && ok "début de pause" || echec "pause : $R"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$SALMA" -H 'Accept: application/json' -d "_csrf=faux&type=sortie" "$URL/api/badger.php")
[ "$CODE" = 419 ] && ok "jeton CSRF invalide refusé (419)" || echec "CSRF : $CODE"

# --- Demande de congé ---
DEBUT=$(php -r 'require "src/bootstrap.php"; echo Calendrier::decalerJoursOuvres(new DateTimeImmutable(), 30)->format("Y-m-d");')
curl -s -b "$AGENT" "$URL/conges.php" -o "$TMP/conges.html"
curl -s -b "$AGENT" -c "$AGENT" -o /dev/null --data-urlencode "_csrf=$(jeton "$TMP/conges.html")" \
    -d "type=administratif&date_debut=$DEBUT&date_fin=$DEBUT&motif=Rendez-vous" "$URL/conges.php"
curl -s -b "$AGENT" "$URL/conges.php" | grep -q "transmise à votre chef de service" && ok "demande déposée et transmise au chef" || echec "dépôt de congé"
curl -s -b "$AGENT" -H 'Accept: application/json' "$URL/api/jours.php?debut=2026-11-02&fin=2026-11-06" | grep -q '"jours":4' \
    && ok "aperçu des jours décomptés (Marche Verte exclue)" || echec "aperçu jours"

# --- Droits ---
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$AGENT" "$URL/employes.php")" = 403 ] && ok "fonctionnaire : gestion des agents interdite (403)" || echec "droits personnel"
[ "$(curl -s -o /dev/null -w '%{http_code}' -b "$AGENT" "$URL/validation.php")" = 403 ] && ok "fonctionnaire : validation interdite (403)" || echec "droits validation"
curl -s -b "$AGENT" "$URL/pointages.php?employe=1" | grep -q "Pointages de" && echec "fonctionnaire voit les pointages d'autrui" || ok "fonctionnaire : pointages d'autrui inaccessibles"

# id de la demande « Rendez-vous » = la plus récente
ID=$(php -r 'require "src/bootstrap.php"; echo Database::connexion()->query("SELECT MAX(id) FROM demandes_conges")->fetchColumn();')

# --- Niveau 1 : avis du chef de service ---
[ "$(connexion "$CHEF" chef@demo.test)" = 302 ] && ok "connexion chef de service" || echec "connexion chef"
curl -s -b "$CHEF" "$URL/validation.php" -o "$TMP/validation.html"
grep -q "Rendez-vous" "$TMP/validation.html" && ok "la demande apparaît chez le chef" || echec "demande absente chez le chef"
curl -s -b "$CHEF" -c "$CHEF" -o /dev/null --data-urlencode "_csrf=$(jeton "$TMP/validation.html")" -d "id=$ID&decision=oui" "$URL/validation.php"
curl -s -b "$AGENT" "$URL/conges.php" | grep -q "pastille-favorable" && ok "avis favorable du chef enregistré" || echec "avis"

# --- Niveau 2 : décision du directeur ---
[ "$(connexion "$DIR" directeur@demo.test)" = 302 ] && ok "connexion directeur" || echec "connexion directeur"
curl -s -b "$DIR" "$URL/validation.php" -o "$TMP/validation.html"
curl -s -b "$DIR" -c "$DIR" -o /dev/null --data-urlencode "_csrf=$(jeton "$TMP/validation.html")" -d "id=$ID&decision=oui" "$URL/validation.php"
curl -s -b "$AGENT" "$URL/conges.php" | grep -q "pastille-approuvee\">Accordé" && ok "congé accordé par le directeur" || echec "décision"
curl -s -b "$CHEF" "$URL/pointages.php?employe=5" | grep -q "Pointages de Youssef" && ok "chef : pointages de son service visibles" || echec "chef pointages"

# --- Borne (Nadia est en congé aujourd'hui dans la démo : aucun badge du jour) ---
curl -s -c "$TMP/borne.txt" "$URL/borne.php" -o "$TMP/borne.html"
curl -s -b "$TMP/borne.txt" -c "$TMP/borne.txt" -o /dev/null --data-urlencode "_csrf=$(jeton "$TMP/borne.html")" \
    -d "matricule=F006&pin=6789&type=entree" "$URL/borne.php"
curl -s -b "$TMP/borne.txt" "$URL/borne.php" | grep -q "Bonjour Nadia" && ok "borne : badge par matricule + PIN" || echec "borne"

# --- Bureau du personnel : calendrier annuel et export ---
[ "$(connexion "$RH" personnel@demo.test)" = 302 ] && ok "connexion bureau du personnel" || echec "connexion personnel"
curl -s -b "$RH" "$URL/calendrier.php?annee=2027" -o "$TMP/cal.html"
curl -s -b "$RH" -c "$RH" -o /dev/null --data-urlencode "_csrf=$(jeton "$TMP/cal.html")" \
    --data-urlencode "action=ajouter_ferie" -d "date=2027-03-10" --data-urlencode "nom=Aïd al-Fitr" "$URL/calendrier.php?annee=2027"
curl -s -b "$AGENT" -H 'Accept: application/json' "$URL/api/jours.php?debut=2027-03-08&fin=2027-03-12" | grep -q '"jours":4' \
    && ok "fête religieuse saisie → exclue du décompte" || echec "fête religieuse"
curl -s -b "$RH" "$URL/export.php?type=pointages&format=standard" | head -1 | grep -q "^matricule,nom,prenom" \
    && ok "export CSV des pointages" || echec "export CSV"
DU=$(php -r 'echo (new DateTime("-30 days"))->format("Y-m-d");')
LIGNES=$(curl -s -b "$RH" "$URL/export.php?type=pointages&format=standard&du=$DU" | wc -l)
[ "$LIGNES" -gt 10 ] && ok "export : $LIGNES lignes" || echec "export vide"
curl -s -b "$RH" "$URL/export.php?type=pointages&format=standard&du=$DU" | grep -q "Retard" && ok "export : retards signalés" || echec "retards absents de l'export"

# --- Aucune erreur PHP ---
for page in tableau-de-bord pointages conges validation equipe employes export calendrier; do
    curl -s -b "$RH" "$URL/$page.php" -o "$TMP/p.html"
    grep -qiE "Warning|Fatal error|Deprecated|Notice" "$TMP/p.html" && echec "erreur PHP sur $page"
done
ok "aucune erreur PHP sur les pages du bureau du personnel"

echo
echo "$REUSSIS vérifications de bout en bout réussies"
