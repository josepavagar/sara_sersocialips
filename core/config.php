<?php
/**
 * Detecta la BASE_URL automáticamente a partir de la ubicación del proyecto.
 * Funciona sin importar el nombre de la carpeta o si está en subdirectorio de htdocs.
 */
if (!defined('BASE_URL')) {
    $root    = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    define('BASE_URL', rtrim(str_replace($docRoot, '', $root), '/'));
}
