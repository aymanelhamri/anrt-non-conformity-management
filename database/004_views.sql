USE anrt_qualite;

CREATE OR REPLACE VIEW v_personnel AS
SELECT
  p.matricule,
  p.nom_prenom,
  p.civilite,
  p.grade,
  p.categorie,
  p.affectation,
  d.direction,
  d.division,
  d.service,
  d.responsabilite,
  CASE
    WHEN a.matricule IS NOT NULL
     AND (a.Date_Fin IS NULL OR a.Date_Fin > CURRENT_TIMESTAMP)
    THEN TRUE ELSE FALSE
  END AS acces_actif
FROM pers AS p
LEFT JOIN direction AS d ON d.matricule = p.matricule
LEFT JOIN acces AS a ON a.matricule = p.matricule;

CREATE OR REPLACE VIEW v_actions_en_retard AS
SELECT
  a.id,
  a.reference,
  pr.code AS processus_code,
  pr.nom AS processus,
  a.responsable_matricule,
  p.nom_prenom AS responsable,
  a.date_echeance,
  DATEDIFF(CURRENT_DATE, a.date_echeance) AS jours_retard,
  a.statut_code
FROM actions AS a
JOIN processus AS pr ON pr.id = a.processus_id
JOIN pers AS p ON p.matricule = a.responsable_matricule
JOIN statuts AS s ON s.code = a.statut_code
WHERE s.est_final = FALSE
  AND a.date_echeance < CURRENT_DATE;

CREATE OR REPLACE VIEW v_nc_en_retard AS
SELECT
  nc.id,
  nc.reference,
  pr.code AS processus_code,
  pr.nom AS processus,
  nc.responsable_matricule,
  p.nom_prenom AS responsable,
  nc.date_echeance,
  DATEDIFF(CURRENT_DATE, nc.date_echeance) AS jours_retard,
  nc.statut_code
FROM non_conformites AS nc
JOIN processus AS pr ON pr.id = nc.processus_id
JOIN pers AS p ON p.matricule = nc.responsable_matricule
JOIN statuts AS s ON s.code = nc.statut_code
WHERE s.est_final = FALSE
  AND nc.date_echeance < CURRENT_DATE;

