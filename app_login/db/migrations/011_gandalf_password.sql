USE MYSTICAL_ORACLE_LOGIN_DB;

-- Corrige somente a senha da conta de teste indicada pelo organizador.
UPDATE Account
SET password_hash = '$2y$10$Bctm8jXBYDoVUw5tLMNAyuBThPRqQtkbs4wthfqy5LlcFHjncuCXq'
WHERE username = 'gandalf';
