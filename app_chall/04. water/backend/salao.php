<?php
/**
 * OBSOLETO — o "Salão das Marés" foi removido na simplificação do desafio.
 * O caminho atual passa por Stored XSS, ingresso das profundezas e Reflected XSS
 * na consulta da Arquivista. Este endpoint permanece obsoleto.
 * Mantido apenas como 410 para não quebrar builds antigos. Pode ser apagado.
 */
require __DIR__ . '/lib.php';
challenge_json(['error' => 'Esta câmara secou. Procure o arquivo das profundezas.'], 410);
