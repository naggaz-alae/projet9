-- Temps & Congés — Direction provinciale du Transport et de la Logistique de Bouarfa (Maroc), stage de mars à juillet 2021
-- Schéma MySQL 8 / MariaDB 10.4+

CREATE TABLE IF NOT EXISTS employes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    matricule     VARCHAR(20)  NOT NULL UNIQUE,
    prenom        VARCHAR(80)  NOT NULL,
    nom           VARCHAR(80)  NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    mot_de_passe  VARCHAR(255) NOT NULL,
    pin           VARCHAR(255) NULL,
    role          ENUM('fonctionnaire', 'chef_service', 'directeur', 'personnel') NOT NULL DEFAULT 'fonctionnaire',
    manager_id    INT UNSIGNED NULL,
    service       VARCHAR(80)  NULL,
    date_entree   DATE         NULL,
    actif         TINYINT(1)   NOT NULL DEFAULT 1,
    cree_le       DATETIME     NOT NULL,
    CONSTRAINT fk_employes_manager FOREIGN KEY (manager_id) REFERENCES employes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pointages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employe_id   INT UNSIGNED NOT NULL,
    type         ENUM('entree', 'debut_pause', 'fin_pause', 'sortie') NOT NULL,
    horodatage   DATETIME NOT NULL,
    source       ENUM('web', 'borne', 'correction') NOT NULL DEFAULT 'web',
    INDEX idx_pointages_employe_horodatage (employe_id, horodatage),
    CONSTRAINT fk_pointages_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soldes (
    employe_id    INT UNSIGNED NOT NULL,
    type          ENUM('administratif', 'exceptionnel') NOT NULL,
    annee         SMALLINT UNSIGNED NOT NULL,
    jours_acquis  DECIMAL(5,1) NOT NULL,
    PRIMARY KEY (employe_id, type, annee),
    CONSTRAINT fk_soldes_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demandes_conges (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    employe_id            INT UNSIGNED NOT NULL,
    type                  ENUM('administratif', 'exceptionnel') NOT NULL,
    date_debut            DATE NOT NULL,
    date_fin              DATE NOT NULL,
    debut_apres_midi      TINYINT(1) NOT NULL DEFAULT 0,
    fin_midi              TINYINT(1) NOT NULL DEFAULT 0,
    nb_jours              DECIMAL(5,1) NOT NULL,
    motif                 VARCHAR(255) NULL,
    statut                ENUM('en_attente_avis', 'en_attente_decision', 'approuvee', 'refusee', 'annulee')
                          NOT NULL DEFAULT 'en_attente_avis',
    avis                  ENUM('favorable', 'defavorable') NULL,
    avis_par              INT UNSIGNED NULL,
    avis_commentaire      VARCHAR(255) NULL,
    avis_le               DATETIME NULL,
    valideur_id           INT UNSIGNED NULL,
    commentaire_valideur  VARCHAR(255) NULL,
    traitee_le            DATETIME NULL,
    cree_le               DATETIME NOT NULL,
    INDEX idx_demandes_employe (employe_id, statut),
    INDEX idx_demandes_dates (date_debut, date_fin),
    CONSTRAINT fk_demandes_employe FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE,
    CONSTRAINT fk_demandes_avis FOREIGN KEY (avis_par) REFERENCES employes(id) ON DELETE SET NULL,
    CONSTRAINT fk_demandes_valideur FOREIGN KEY (valideur_id) REFERENCES employes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feries_variables (
    date_ferie  DATE         NOT NULL PRIMARY KEY,
    nom         VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS horaires_speciaux (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nom            VARCHAR(100) NOT NULL,
    date_debut     DATE         NOT NULL,
    date_fin       DATE         NOT NULL,
    heure_debut    CHAR(5)      NOT NULL,
    heure_fin      CHAR(5)      NOT NULL,
    pause_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tentatives_connexion (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cle         VARCHAR(200) NOT NULL,
    horodatage  DATETIME NOT NULL,
    INDEX idx_tentatives_cle (cle, horodatage)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
