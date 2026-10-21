<?php
declare(strict_types=1);

ob_start();
require __DIR__ . '/en_el_ceo_hoy_participantes.php';
$page = (string)ob_get_clean();

$originalStyle = <<<'HTML'
  <style>
    .fiestas-frame {
      display: none;
    }

    body {
      position: static;
      overflow-x: visible;
    }

    .page {
      z-index: auto;
    }

    .brand {
      flex-direction: row;
      align-items: center;
      gap: 10px;
      max-width: 260px;
    }

    @media (max-width: 980px) {
      .brand {
        align-self: flex-start;
        width: auto;
        align-items: center;
      }

      .brand-main {
        max-width: 260px;
      }
    }

    @media print {
      .fiestas-frame {
        display: none;
      }
    }
  </style>
</head>
HTML;

echo str_replace('</head>', $originalStyle, $page);
