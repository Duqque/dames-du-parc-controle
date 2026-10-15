<?php
declare(strict_types=1);

namespace DDP;

/**
 * Import et export CSV.
 *
 * Import : fichier texte uniquement (taille et nombre de lignes limités, octets nuls refusés, encodage UTF-8 exigé), séparateur
 * « ; » ou « , » détecté, en-tête obligatoire. Chaque ligne est vérifiée : format du code, doublon dans le fichier, doublon en base,
 * texte commençant par = + - @ (formule de tableur) refusé. Les lignes correctes sont insérées dans une transaction ; un rapport
 * détaille les lignes refusées.
 *
 * Export : toute cellule commençant par = + - @ tabulation ou retour chariot est préfixée d'une apostrophe (pas d'exécution de
 * formule à l'ouverture dans un tableur), séparateur « ; » et BOM UTF-8 pour Excel.
 */
final class Csv
{
    private const COLUMNS = [
        'code' => ['code', 'jeton', 'token', 'billet', 'numero', 'ticket'],
        'nom' => ['nom', 'name', 'titulaire', 'holder', 'participante', 'participant'],
        'reference' => ['reference', 'ref', 'commande', 'membre', 'numero_membre'],
    ];

    /**
     * @return array{ok:bool, error?:string, total:int, inserted:int, duplicates:int, errors:int, lines:array<int,array{line:int,reason:string}>, generated:int}
     */
    public static function import(string $path, string $filename, int $eventId, int $userId): array
    {
        $empty = ['total' => 0, 'inserted' => 0, 'duplicates' => 0, 'errors' => 0, 'lines' => [], 'generated' => 0];
        $max = (int) App::config('import.max_bytes', 5 * 1024 * 1024);
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Fichier vide.'] + $empty;
        }
        if ($size > $max) {
            return ['ok' => false, 'error' => 'Fichier trop volumineux (maximum ' . round($max / 1048576) . ' Mo).'] + $empty;
        }
        if (!preg_match('/\.(csv|txt)$/i', $filename)) {
            return ['ok' => false, 'error' => 'Seuls les fichiers .csv sont acceptés.'] + $empty;
        }
        $content = (string) file_get_contents($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        if (str_contains($content, "\0") || !in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values'], true)) {
            return ['ok' => false, 'error' => 'Le fichier n\'est pas un fichier texte CSV.'] + $empty;
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? '';
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $first = (string) ($lines[0] ?? '');
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';

        $header = array_map(static fn ($h) => self::norm((string) $h), str_getcsv($first, $sep, '"', ''));
        $idx = [];
        foreach (self::COLUMNS as $key => $names) {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    $idx[$key] = $i;
                    break;
                }
            }
        }
        if (!isset($idx['code']) && !isset($idx['nom']) && !isset($idx['reference'])) {
            return ['ok' => false, 'error' => 'En-tête non reconnu. Colonnes attendues : code ; nom ; reference (au moins une).'] + $empty;
        }

        $maxRows = (int) App::config('import.max_rows', 20000);
        $rows = [];
        $report = [];
        $seen = [];
        $dup = 0;
        $err = 0;
        $total = 0;
        foreach (array_slice($lines, 1, null, true) as $n => $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $total++;
            $lineNo = $n + 1;
            if ($total > $maxRows) {
                return ['ok' => false, 'error' => "Trop de lignes (maximum $maxRows)."] + $empty;
            }
            $cells = str_getcsv((string) $line, $sep, '"', '');
            $code = isset($idx['code']) ? trim((string) ($cells[$idx['code']] ?? '')) : '';
            $nom = isset($idx['nom']) ? trim((string) ($cells[$idx['nom']] ?? '')) : '';
            $ref = isset($idx['reference']) ? trim((string) ($cells[$idx['reference']] ?? '')) : '';
            if ($code !== '' && preg_match('/^[\d\s-]{6,80}$/', $code)) {
                $code = preg_replace('/[\s-]+/', '', $code) ?? $code;
            }
            if ($code !== '' && !TicketCode::isValidFormat($code)) {
                $err++;
                $report[] = ['line' => $lineNo, 'reason' => 'Code au mauvais format (6 à 64 caractères : lettres, chiffres, - et _).'];
                continue;
            }
            foreach (['nom' => $nom, 'référence' => $ref] as $label => $val) {
                if ($val !== '' && preg_match('/^[=+\-@\t\r]/', $val)) {
                    $err++;
                    $report[] = ['line' => $lineNo, 'reason' => "Champ $label refusé : il commence par un caractère de formule (= + - @)."];
                    continue 2;
                }
            }
            if (mb_strlen($nom) > 160 || mb_strlen($ref) > 80) {
                $err++;
                $report[] = ['line' => $lineNo, 'reason' => 'Nom (160 caractères) ou référence (80) trop long.'];
                continue;
            }
            if ($code !== '') {
                if (isset($seen[$code])) {
                    $dup++;
                    $report[] = ['line' => $lineNo, 'reason' => "Doublon dans le fichier (même code qu'à la ligne {$seen[$code]})."];
                    continue;
                }
                $seen[$code] = $lineNo;
            }
            $rows[] = ['line' => $lineNo, 'code' => $code, 'nom' => $nom, 'ref' => $ref];
        }
        if ($total === 0) {
            return ['ok' => false, 'error' => 'Aucune ligne de données sous l\'en-tête.'] + $empty;
        }

        // Codes déjà présents en base (tous événements confondus : un jeton est unique).
        $existing = [];
        $codes = array_values(array_filter(array_column($rows, 'code')));
        $db = App::db();
        foreach (array_chunk($codes, 500) as $chunk) {
            $st = $db->prepare('SELECT token FROM tickets WHERE token IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $st->execute($chunk);
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $tk) {
                $existing[$tk] = true;
            }
        }

        $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO imports (user_id, event_id, filename, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([$userId, $eventId, mb_substr(basename($filename), 0, 255)]);
            $importId = (int) $db->lastInsertId();
            $ins = $db->prepare('INSERT INTO tickets (token, event_id, holder_name, reference, status, created_at, import_id) VALUES (?, ?, ?, ?, \'valid\', UTC_TIMESTAMP(), ?)');
            $inserted = 0;
            $generated = 0;
            foreach ($rows as $r) {
                if ($r['code'] !== '' && isset($existing[$r['code']])) {
                    $dup++;
                    $report[] = ['line' => $r['line'], 'reason' => 'Doublon : ce code existe déjà en base.'];
                    continue;
                }
                $code = $r['code'];
                if ($code === '') {
                    do {
                        $code = TicketCode::generate();
                    } while (isset($existing[$code]));
                    $generated++;
                }
                $ins->execute([$code, $eventId, $r['nom'] !== '' ? $r['nom'] : null, $r['ref'] !== '' ? $r['ref'] : null, $importId]);
                $existing[$code] = true;
                $inserted++;
            }
            usort($report, static fn ($a, $b) => $a['line'] <=> $b['line']);
            $db->prepare('UPDATE imports SET total_rows = ?, inserted_rows = ?, duplicate_rows = ?, error_rows = ?, report = ? WHERE id = ?')
                ->execute([$total, $inserted, $dup, $err, json_encode(array_slice($report, 0, 500), JSON_UNESCAPED_UNICODE), $importId]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        return ['ok' => true, 'import_id' => $importId, 'total' => $total, 'inserted' => $inserted, 'duplicates' => $dup, 'errors' => $err, 'generated' => $generated, 'lines' => array_slice($report, 0, 200)];
    }

    private static function norm(string $h): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'ç' => 'c', 'ô' => 'o', 'û' => 'u', 'î' => 'i', '°' => '']);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $h), '_');
    }

    /** Neutralise les formules de tableur. */
    public static function cell(mixed $v): string
    {
        $s = (string) ($v ?? '');
        if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
            $s = "'" . $s;
        }
        return $s;
    }

    /** Envoie un CSV en téléchargement (en-têtes + lignes), puis termine le script. */
    public static function download(string $filename, array $header, iterable $rows): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'cell'], $header), ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map([self::class, 'cell'], array_values($r)), ';', '"', '');
        }
        fclose($out);
        exit;
    }
}
