-- Base de données de gestion des actions d'amélioration et non-conformités
-- Cible : MySQL 8.0+

CREATE DATABASE IF NOT EXISTS anrt_qualite
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE anrt_qualite;

-- Personnel : structure demandée par la superviseure, avec les matricules
-- harmonisés et les contraintes nécessaires à l'application.

CREATE TABLE IF NOT EXISTS pers (
  matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  nom_prenom VARCHAR(60) DEFAULT NULL,
  echelle VARCHAR(60) DEFAULT NULL,
  echelon VARCHAR(60) DEFAULT NULL,
  categorie VARCHAR(60) DEFAULT NULL,
  affectation VARCHAR(10) DEFAULT NULL,
  adresse VARCHAR(255) DEFAULT NULL,
  prioritaire VARCHAR(8) DEFAULT NULL,
  date_recrutement VARCHAR(20) DEFAULT NULL,
  cin VARCHAR(15) DEFAULT NULL,
  civilite VARCHAR(10) DEFAULT NULL,
  grade VARCHAR(60) DEFAULT NULL,
  ps VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (matricule),
  KEY idx_pers_nom_prenom (nom_prenom)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS direction (
  matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  nom VARCHAR(30) DEFAULT NULL,
  direction VARCHAR(60) DEFAULT NULL,
  division VARCHAR(80) DEFAULT NULL,
  service VARCHAR(80) DEFAULT NULL,
  responsabilite VARCHAR(30) DEFAULT NULL,
  PRIMARY KEY (matricule),
  CONSTRAINT fk_direction_pers
    FOREIGN KEY (matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acces (
  matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  nom VARCHAR(60) DEFAULT NULL,
  passe VARCHAR(255) NOT NULL,
  Date_Deb TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  Date_Fin TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (matricule),
  CONSTRAINT fk_acces_pers
    FOREIGN KEY (matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS personnel_validation (
  num INT UNSIGNED NOT NULL AUTO_INCREMENT,
  matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  nom_prenom VARCHAR(600) NOT NULL,
  mat_val VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  nom_valideur VARCHAR(200) NOT NULL,
  niv_val VARCHAR(20) NOT NULL,
  PRIMARY KEY (num),
  KEY idx_personnel_validation_matricule (matricule),
  KEY idx_personnel_validation_valideur (mat_val),
  CONSTRAINT fk_personnel_validation_pers
    FOREIGN KEY (matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_personnel_validation_valideur
    FOREIGN KEY (mat_val) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profils_acces (
  code VARCHAR(30) NOT NULL,
  libelle VARCHAR(100) NOT NULL,
  PRIMARY KEY (code),
  UNIQUE KEY uq_profils_acces_libelle (libelle)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS acces_profil (
  matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  profil_code VARCHAR(30) NOT NULL,
  PRIMARY KEY (matricule, profil_code),
  CONSTRAINT fk_acces_profil_acces
    FOREIGN KEY (matricule) REFERENCES acces (matricule)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_acces_profil_profil
    FOREIGN KEY (profil_code) REFERENCES profils_acces (code)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Référentiels métier

CREATE TABLE IF NOT EXISTS processus (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL,
  nom VARCHAR(200) NOT NULL,
  actif BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_processus_code (code),
  UNIQUE KEY uq_processus_nom (nom)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS origines_action (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  libelle VARCHAR(150) NOT NULL,
  actif BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_origines_action_libelle (libelle)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS types_nc (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  libelle VARCHAR(255) NOT NULL,
  actif BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_types_nc_libelle (libelle)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS natures_service (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  libelle VARCHAR(255) NOT NULL,
  actif BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (id),
  UNIQUE KEY uq_natures_service_libelle (libelle)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS statuts (
  code VARCHAR(20) NOT NULL,
  libelle VARCHAR(50) NOT NULL,
  est_final BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (code),
  UNIQUE KEY uq_statuts_libelle (libelle)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS evaluations_efficacite (
  code VARCHAR(20) NOT NULL,
  libelle VARCHAR(50) NOT NULL,
  PRIMARY KEY (code),
  UNIQUE KEY uq_evaluations_efficacite_libelle (libelle)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS verifications_traitement (
  code VARCHAR(20) NOT NULL,
  libelle VARCHAR(50) NOT NULL,
  PRIMARY KEY (code),
  UNIQUE KEY uq_verifications_traitement_libelle (libelle)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS processus_responsable (
  processus_id INT UNSIGNED NOT NULL,
  matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  est_principal BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (processus_id, matricule),
  CONSTRAINT fk_processus_responsable_processus
    FOREIGN KEY (processus_id) REFERENCES processus (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_processus_responsable_pers
    FOREIGN KEY (matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Compteur transactionnel utilisé pour produire FA-SI-001-26 / NC-SI-001-26.
CREATE TABLE IF NOT EXISTS compteurs_reference (
  type_document ENUM('FA', 'NC') NOT NULL,
  processus_id INT UNSIGNED NOT NULL,
  annee SMALLINT UNSIGNED NOT NULL,
  derniere_valeur INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (type_document, processus_id, annee),
  CONSTRAINT fk_compteurs_reference_processus
    FOREIGN KEY (processus_id) REFERENCES processus (id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Actions d'amélioration et non-conformités

CREATE TABLE IF NOT EXISTS actions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference VARCHAR(50) NOT NULL,
  processus_id INT UNSIGNED NOT NULL,
  origine_id INT UNSIGNED NOT NULL,
  initiee_par VARCHAR(200) NOT NULL,
  situation_a_traiter TEXT NOT NULL,
  action_a_realiser TEXT NOT NULL,
  responsable_matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  date_creation DATE NOT NULL,
  date_echeance DATE DEFAULT NULL,
  date_cloture DATE DEFAULT NULL,
  statut_code VARCHAR(20) NOT NULL DEFAULT 'OUVERTE',
  verification_efficacite TEXT DEFAULT NULL,
  analyse_causes TEXT DEFAULT NULL,
  evaluation_efficacite_code VARCHAR(20) DEFAULT NULL,
  commentaire TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_actions_reference (reference),
  KEY idx_actions_processus (processus_id),
  KEY idx_actions_responsable (responsable_matricule),
  KEY idx_actions_statut_echeance (statut_code, date_echeance),
  CONSTRAINT fk_actions_processus
    FOREIGN KEY (processus_id) REFERENCES processus (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_actions_origine
    FOREIGN KEY (origine_id) REFERENCES origines_action (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_actions_responsable
    FOREIGN KEY (responsable_matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_actions_statut
    FOREIGN KEY (statut_code) REFERENCES statuts (code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_actions_evaluation
    FOREIGN KEY (evaluation_efficacite_code)
    REFERENCES evaluations_efficacite (code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_actions_dates
    CHECK (date_echeance IS NULL OR date_echeance >= date_creation),
  CONSTRAINT chk_actions_cloture
    CHECK (date_cloture IS NULL OR date_cloture >= date_creation)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS non_conformites (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference VARCHAR(50) NOT NULL,
  processus_id INT UNSIGNED NOT NULL,
  type_nc_id INT UNSIGNED NOT NULL,
  nature_service_id INT UNSIGNED NOT NULL,
  enregistree_par_matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  description TEXT NOT NULL,
  traitement TEXT NOT NULL,
  responsable_matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  date_creation DATE NOT NULL,
  date_echeance DATE DEFAULT NULL,
  date_cloture DATE DEFAULT NULL,
  statut_code VARCHAR(20) NOT NULL DEFAULT 'OUVERTE',
  verification_traitement_code VARCHAR(20) DEFAULT NULL,
  action_corrective_necessaire BOOLEAN NOT NULL DEFAULT FALSE,
  analyse_causes TEXT DEFAULT NULL,
  evaluation_efficacite_code VARCHAR(20) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_non_conformites_reference (reference),
  KEY idx_non_conformites_processus (processus_id),
  KEY idx_non_conformites_responsable (responsable_matricule),
  KEY idx_non_conformites_statut_echeance (statut_code, date_echeance),
  CONSTRAINT fk_non_conformites_processus
    FOREIGN KEY (processus_id) REFERENCES processus (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_non_conformites_type
    FOREIGN KEY (type_nc_id) REFERENCES types_nc (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_non_conformites_nature
    FOREIGN KEY (nature_service_id) REFERENCES natures_service (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_non_conformites_enregistreur
    FOREIGN KEY (enregistree_par_matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_non_conformites_responsable
    FOREIGN KEY (responsable_matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_non_conformites_statut
    FOREIGN KEY (statut_code) REFERENCES statuts (code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_non_conformites_verification
    FOREIGN KEY (verification_traitement_code)
    REFERENCES verifications_traitement (code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_non_conformites_evaluation
    FOREIGN KEY (evaluation_efficacite_code)
    REFERENCES evaluations_efficacite (code)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_non_conformites_dates
    CHECK (date_echeance IS NULL OR date_echeance >= date_creation),
  CONSTRAINT chk_non_conformites_cloture
    CHECK (date_cloture IS NULL OR date_cloture >= date_creation)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nc_action (
  non_conformite_id INT UNSIGNED NOT NULL,
  action_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (non_conformite_id, action_id),
  CONSTRAINT fk_nc_action_nc
    FOREIGN KEY (non_conformite_id) REFERENCES non_conformites (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_nc_action_action
    FOREIGN KEY (action_id) REFERENCES actions (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Demandes d'accès et validation

CREATE TABLE IF NOT EXISTS demandeurs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  email VARCHAR(255) NOT NULL,
  telephone VARCHAR(30) DEFAULT NULL,
  organisme VARCHAR(200) DEFAULT NULL,
  fonction VARCHAR(150) DEFAULT NULL,
  statut VARCHAR(30) NOT NULL DEFAULT 'EN_ATTENTE',
  date_creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_demandeurs_email (email),
  KEY idx_demandeurs_statut (statut)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS validations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  demandeur_id INT UNSIGNED NOT NULL,
  decision VARCHAR(20) NOT NULL,
  motif TEXT DEFAULT NULL,
  validateur_matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  date_validation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_validations_demandeur (demandeur_id),
  KEY idx_validations_validateur (validateur_matricule),
  CONSTRAINT fk_validations_demandeur
    FOREIGN KEY (demandeur_id) REFERENCES demandeurs (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_validations_validateur
    FOREIGN KEY (validateur_matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_validations_decision
    CHECK (decision IN ('EN_ATTENTE', 'ACCEPTEE', 'REFUSEE'))
) ENGINE=InnoDB;

-- Notifications et traçabilité demandées dans la fiche de stage

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destinataire_matricule VARCHAR(60) COLLATE utf8mb4_bin NOT NULL,
  action_id INT UNSIGNED DEFAULT NULL,
  non_conformite_id INT UNSIGNED DEFAULT NULL,
  type_notification VARCHAR(30) NOT NULL,
  titre VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  lue_le TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_destinataire (destinataire_matricule, lue_le),
  CONSTRAINT fk_notifications_destinataire
    FOREIGN KEY (destinataire_matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notifications_action
    FOREIGN KEY (action_id) REFERENCES actions (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notifications_nc
    FOREIGN KEY (non_conformite_id) REFERENCES non_conformites (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS journal_audit (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  acteur_matricule VARCHAR(60) COLLATE utf8mb4_bin DEFAULT NULL,
  operation VARCHAR(30) NOT NULL,
  entite VARCHAR(60) NOT NULL,
  entite_id VARCHAR(60) NOT NULL,
  anciennes_valeurs JSON DEFAULT NULL,
  nouvelles_valeurs JSON DEFAULT NULL,
  adresse_ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_journal_audit_entite (entite, entite_id),
  KEY idx_journal_audit_acteur_date (acteur_matricule, created_at),
  CONSTRAINT fk_journal_audit_acteur
    FOREIGN KEY (acteur_matricule) REFERENCES pers (matricule)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;
