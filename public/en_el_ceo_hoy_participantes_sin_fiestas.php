<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/en_el_ceo_hoy_participantes.php';
$page = (string)ob_get_clean();

$neutralStyle = <<<'HTML'
  <style>
    .fiestas-frame,
    .flag-wave-wrap {
      display: none;
    }

    body {
      position: static;
      overflow-x: visible;
    }

    .page {
      z-index: auto;
    }
  </style>
</head>
HTML;

echo str_replace('</head>', $neutralStyle, $page);
