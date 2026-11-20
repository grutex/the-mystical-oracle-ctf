-- ============================================================================
--  MYSTICAL ORACLE CTF — DESAFIO 05 · EARTH (Relicário da Terra)
--  Banco do desafio de SQL Injection.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS terra_relicario
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE terra_relicario;

-- ---- FORCE FRESH ----
DROP TABLE IF EXISTS relicarios;
DROP TABLE IF EXISTS cofre;
DROP TABLE IF EXISTS chaveiro;

-- ---- TABELA PÚBLICA (a busca consulta esta) ----
CREATE TABLE relicarios (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(80)  NOT NULL,
    descricao VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO relicarios (nome, descricao) VALUES
    ('Amuleto de Jade',    'Joia esculpida nas minas profundas da montanha.'),
    ('Runa de Granito',    'Inscricoes que resistem ao passar das eras.'),
    ('Cristal de Quartzo', 'Vibra com a energia telurica do subsolo.'),
    ('Selo de Obsidiana',  'Vidro vulcanico, negro como a raiz da terra.');

-- ---- COFRE (guarda a CIFRA) ----
CREATE TABLE cofre (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    alg   VARCHAR(120) NOT NULL,
    cifra VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cofre (alg, cifra) VALUES
    ('XOR; cifra em base64; decifre com a chave guardada no chaveiro',
     'KCB1LX0xHh5GQkQcLFgKAlwYbE4QRgFSGx4HN0dFAy1CVxlfB1A=');

-- ---- CHAVEIRO (guarda a CHAVE) ----
CREATE TABLE chaveiro (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    dono  VARCHAR(80)  NOT NULL,
    chave VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO chaveiro (dono, chave) VALUES
    ('guardiao-da-terra', 'gr4n1te-r00ts-d3lv3-b3l0w-th3-0r4cl3s-v4ult-x9');

-- ---- USUÁRIO DA APLICAÇÃO: SELECT-only (limita o estrago do SQLi) ----
CREATE USER IF NOT EXISTS 'relic_ro'@'%' IDENTIFIED BY 'relicpw';
GRANT SELECT ON terra_relicario.* TO 'relic_ro'@'%';
FLUSH PRIVILEGES;

-- flag = XOR(cofre.cifra (base64-decoded), chaveiro.chave)
--      = ORACLE{34rth_un10n_crumbl3s_th3_v4ult}   (NÃO fica armazenada; é derivada)
