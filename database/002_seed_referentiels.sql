-- Référentiels extraits de la feuille « Tables » du classeur Excel fourni.

USE anrt_qualite;

INSERT INTO profils_acces (code, libelle) VALUES
  ('ADMINISTRATEUR', 'Administrateur'),
  ('PILOTE_PROCESSUS', 'Pilote de processus'),
  ('RESPONSABLE', 'Responsable d’action ou de non-conformité'),
  ('CONSULTATION', 'Consultation uniquement')
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);

INSERT INTO processus (code, nom) VALUES
  ('PO', 'Politique & Objectifs'),
  ('PA', 'Pilotage et Amélioration'),
  ('AS', 'Assignation'),
  ('AG', 'Agrément'),
  ('PL', 'Planification'),
  ('CO', 'Coordination'),
  ('FA', 'Facturation'),
  ('HA', 'Achats'),
  ('RH', 'Ressources Humaines'),
  ('SI', 'Système d’information'),
  ('CT', 'Contrôle Technique'),
  ('COM', 'Communication')
ON DUPLICATE KEY UPDATE nom = VALUES(nom), actif = TRUE;

INSERT INTO origines_action (libelle) VALUES
  ('Contexte'),
  ('Besoins des parties prenantes'),
  ('Risques & opportunités'),
  ('Politique & Objectifs'),
  ('Modification processus'),
  ('Analyse des données'),
  ('Réclamations'),
  ('Audit'),
  ('Amélioration'),
  ('Autres')
ON DUPLICATE KEY UPDATE actif = TRUE;

INSERT INTO natures_service (libelle) VALUES
  ('Assignation de fréquences'),
  ('Assignation provisoire de fréquences'),
  ('Mise à jour de base de données'),
  ('Demande d’agrément'),
  ('Demande d’importation de matériel'),
  ('Contrôle de conformité'),
  ('Traitement de brouillage'),
  ('Conformité au PNF et au RR'),
  ('Informations sur les fréquences à usage libre'),
  ('Coordination des fréquences de radiodiffusion'),
  ('Coordination avec l’UIT'),
  ('Notification des fréquences'),
  ('Facturation des ERPT'),
  ('Facturation des RIRs'),
  ('Facturation stations aéronefs, navires, amateurs, CB, etc.'),
  ('Autre')
ON DUPLICATE KEY UPDATE actif = TRUE;

INSERT INTO types_nc (libelle) VALUES
  ('Délai de traitement non conforme'),
  ('Doute potentiel ou avéré sur le résultat d’un contrôle'),
  ('Doute potentiel ou avéré sur le résultat d’une analyse technique'),
  ('Manque d’informations ou de documents dans les dossiers/demandes'),
  ('Données traitées jugées non fiables'),
  ('Situation non prévue dans les procédures applicables'),
  ('Autre'),
  ('[ALERTE] Gestion des exceptions (Serveur Facturation/Recouvrement)')
ON DUPLICATE KEY UPDATE actif = TRUE;

INSERT INTO statuts (code, libelle, est_final) VALUES
  ('OUVERTE', 'Ouverte', FALSE),
  ('CLOTUREE', 'Clôturée', TRUE),
  ('ANNULEE', 'Annulée', TRUE),
  ('AJOURNEE', 'Ajournée', FALSE)
ON DUPLICATE KEY UPDATE
  libelle = VALUES(libelle), est_final = VALUES(est_final);

INSERT INTO evaluations_efficacite (code, libelle) VALUES
  ('OUI', 'Oui'),
  ('PARTIELLE', 'Partielle'),
  ('NON', 'Non')
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);

INSERT INTO verifications_traitement (code, libelle) VALUES
  ('OK', 'OK'),
  ('NON_OK', 'Non OK')
ON DUPLICATE KEY UPDATE libelle = VALUES(libelle);
