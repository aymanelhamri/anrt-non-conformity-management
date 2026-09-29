-- Procédure de génération atomique des références métier.
-- L'appelant doit utiliser la référence retournée lors de l'INSERT de l'action
-- ou de la non-conformité, dans la même transaction.

USE anrt_qualite;

DROP PROCEDURE IF EXISTS generer_reference;

DELIMITER $$

CREATE PROCEDURE generer_reference(
  IN p_type_document VARCHAR(2),
  IN p_processus_id INT UNSIGNED,
  IN p_date DATE,
  OUT p_reference VARCHAR(50)
)
BEGIN
  DECLARE v_numero INT UNSIGNED;
  DECLARE v_code_processus VARCHAR(30);
  DECLARE v_annee SMALLINT UNSIGNED;

  IF p_type_document NOT IN ('FA', 'NC') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Le type de document doit être FA ou NC';
  END IF;

  IF p_date IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'La date du document est obligatoire';
  END IF;

  SELECT MAX(code)
    INTO v_code_processus
    FROM processus
   WHERE id = p_processus_id AND actif = TRUE;

  IF v_code_processus IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Processus inexistant ou inactif';
  END IF;

  SET v_annee = YEAR(p_date);

  INSERT INTO compteurs_reference (
    type_document, processus_id, annee, derniere_valeur
  ) VALUES (
    p_type_document, p_processus_id, v_annee, LAST_INSERT_ID(1)
  )
  ON DUPLICATE KEY UPDATE
    derniere_valeur = LAST_INSERT_ID(derniere_valeur + 1);

  SET v_numero = LAST_INSERT_ID();
  SET p_reference = CONCAT(
    p_type_document, '-', v_code_processus, '-',
    LPAD(v_numero, 3, '0'), '-', DATE_FORMAT(p_date, '%y')
  );
END$$

DELIMITER ;

