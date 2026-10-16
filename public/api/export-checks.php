<?php
declare(strict_types=1);

use DDP\{Audit, Auth, Checks, Csv, Http};

require dirname(__DIR__, 2) . '/src/api.php';

Http::requireMethod('GET');
Auth::requireApi('admin');
$f = Checks::filters($_GET);
Audit::log('export_checks', null, null, $f);
Csv::download('controles-' . date('Y-m-d-His') . '.csv', ['Date et heure', 'Résultat', 'Mode', 'Événement', 'N° billet', 'Titulaire', 'Référence', 'Début du code', 'Contrôleur'], Checks::exportRows($f));
