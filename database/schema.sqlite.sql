-- Temps & Congés — Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021
-- Schéma SQLite (base par défaut : aucun serveur à installer)
-- Dates au format texte 'AAAA-MM-JJ' et 'AAAA-MM-JJ HH:MM:SS' (heure du Maroc)

CREATE TABLE IF NOT EXISTS employes (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    matricule     VARCHAR(20)  NOT NULL UNIQUE,
    prenom        VARCHAR(80)  NOT NULL,
    nom           VARCHAR(80)  NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    mot_de_passe  VARCHAR(255) NOT NULL,
    pin           VARCHAR(255),
    role          VARCHAR(15)  NOT NULL DEFAULT 'fonctionnaire'
                  CHECK (role IN ('fonctionnaire', 'chef_service', 'directeur', 'personnel')),
    manager_id    INTEGER REFERENCES employes(id) ON DELETE SET NULL,
    service       VARCHAR(80),
    date_entree   VARCHAR(10),
    actif         INTEGER      NOT NULL DEFAULT 1,
    cree_le       VARCHAR(19)  NOT NULL
);

CREATE TABLE IF NOT EXISTS pointages (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    employe_id   INTEGER     NOT NULL REFERENCES employes(id) ON DELETE CASCADE,
    type         VARCHAR(12) NOT NULL CHECK (type IN ('entree', 'debut_pause', 'fin_pause', 'sortie')),
    horodatage   VARCHAR(19) NOT NULL,
    source       VARCHAR(10) NOT NULL DEFAULT 'web' CHECK (source IN ('web', 'borne', 'correction'))
);

CREATE INDEX IF NOT EXISTS idx_pointages_employe_horodatage ON pointages (employe_id, horodatage);

-- Droits annuels : une ligne par fonctionnaire, type et année (report éventuel inclus)
CREATE TABLE IF NOT EXISTS soldes (
    employe_id    INTEGER     NOT NULL REFERENCES employes(id) ON DELETE CASCADE,
    type          VARCHAR(15) NOT NULL CHECK (type IN ('administratif', 'exceptionnel')),
    annee         INTEGER     NOT NULL,
    jours_acquis  REAL        NOT NULL,
    PRIMARY KEY (employe_id, type, annee)
);

CREATE TABLE IF NOT EXISTS demandes_conges (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    employe_id            INTEGER     NOT NULL REFERENCES employes(id) ON DELETE CASCADE,
    type                  VARCHAR(15) NOT NULL CHECK (type IN ('administratif', 'exceptionnel')),
    date_debut            VARCHAR(10) NOT NULL,
    date_fin              VARCHAR(10) NOT NULL,
    debut_apres_midi      INTEGER     NOT NULL DEFAULT 0,
    fin_midi              INTEGER     NOT NULL DEFAULT 0,
    nb_jours              REAL        NOT NULL,
    motif                 VARCHAR(255),
    statut                VARCHAR(20) NOT NULL DEFAULT 'en_attente_avis'
                          CHECK (statut IN ('en_attente_avis', 'en_attente_decision', 'approuvee', 'refusee', 'annulee')),
    -- Niveau 1 : avis du chef de service
    avis                  VARCHAR(12) CHECK (avis IN ('favorable', 'defavorable')),
    avis_par              INTEGER REFERENCES employes(id) ON DELETE SET NULL,
    avis_commentaire      VARCHAR(255),
    avis_le               VARCHAR(19),
    -- Niveau 2 : décision du directeur provincial
    valideur_id           INTEGER REFERENCES employes(id) ON DELETE SET NULL,
    commentaire_valideur  VARCHAR(255),
    traitee_le            VARCHAR(19),
    cree_le               VARCHAR(19) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_demandes_employe ON demandes_conges (employe_id, statut);
CREATE INDEX IF NOT EXISTS idx_demandes_dates ON demandes_conges (date_debut, date_fin);

-- Fêtes religieuses : dates fixées chaque année selon l'observation du croissant lunaire
CREATE TABLE IF NOT EXISTS feries_variables (
    date_ferie  VARCHAR(10)  PRIMARY KEY,
    nom         VARCHAR(100) NOT NULL
);

-- Horaires particuliers (ramadan, horaire d'été…) fixés par arrêté
CREATE TABLE IF NOT EXISTS horaires_speciaux (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    nom            VARCHAR(100) NOT NULL,
    date_debut     VARCHAR(10)  NOT NULL,
    date_fin       VARCHAR(10)  NOT NULL,
    heure_debut    VARCHAR(5)   NOT NULL,
    heure_fin      VARCHAR(5)   NOT NULL,
    pause_minutes  INTEGER      NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS tentatives_connexion (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    cle         VARCHAR(200) NOT NULL,
    horodatage  VARCHAR(19)  NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tentatives_cle ON tentatives_connexion (cle, horodatage)
