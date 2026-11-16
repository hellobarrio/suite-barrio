<?php
// Eliminando Barrio Suite da Plugin. I dati restano, a meno che il sito non abbia scelto di eliminarli
// in Barrio → Impostazioni (src/Uninstall.php). WordPress qui non carica il plugin: serve l'autoloader.

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/barrio-suite.php';
BarrioSuite\Plugin::uninstall();
