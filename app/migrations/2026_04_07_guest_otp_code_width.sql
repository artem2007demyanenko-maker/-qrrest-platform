-- Expand otp_codes.code so hardened OTP storage can keep hashes instead of plain codes.
-- Legacy schemas with VARCHAR(10) continue to work via app-level fallback until this runs.

SET @otp_code_length := (
  SELECT CHARACTER_MAXIMUM_LENGTH
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'otp_codes'
    AND COLUMN_NAME = 'code'
  LIMIT 1
);

SET @sql := IF(
  @otp_code_length IS NULL,
  'SELECT 1',
  IF(
    @otp_code_length < 255,
    "ALTER TABLE otp_codes MODIFY COLUMN code VARCHAR(255) NOT NULL",
    'SELECT 1'
  )
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
