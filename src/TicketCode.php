<?php
declare(strict_types=1);

namespace DDP;

/**
 * Jetons des billets et contenu des QR codes.
 *
 * - Jeton : 24 caractères aléatoires (alphabet base32 sans caractères ambigus, ~120 bits d'entropie) : impossible à deviner,
 *   jamais un identifiant numérique consécutif.
 * - QR code : « DDP1.<jeton>.<signature> », signature HMAC-SHA256 tronquée calculée avec le secret du serveur. Un QR code dont la
 *   signature est fausse est refusé (« signature invalide ») avant même la recherche en base.
 * - Saisie manuelle : le jeton seul suffit (ou un code importé d'un autre système, ex. les 12 chiffres des billets du site).
 *   Dans tous les cas, la validité est décidée par la base de données, jamais par la seule lecture du QR code.
 */
final class TicketCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const PREFIX = 'DDP1';
    public const PATTERN = '/^[A-Za-z0-9_-]{6,64}$/';

    public static function generate(int $length = 24): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    public static function isValidFormat(string $code): bool
    {
        return (bool) preg_match(self::PATTERN, $code);
    }

    private static function signature(string $token): string
    {
        $secret = (string) App::config('qr_secret', '');
        if (strlen($secret) < 32) {
            throw new \RuntimeException('qr_secret manquant ou trop court dans la configuration.');
        }
        $raw = substr(hash_hmac('sha256', self::PREFIX . '.' . $token, $secret, true), 0, 12);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Contenu à encoder dans le QR code d'un billet. */
    public static function qrPayload(string $token): string
    {
        return self::PREFIX . '.' . $token . '.' . self::signature($token);
    }

    /**
     * Analyse un code lu ou saisi. Retourne ['token' => string|null, 'signed' => bool, 'error' => null|'format'|'signature'].
     * Les espaces d'une saisie manuelle sont ignorés, ainsi que les tirets d'un code uniquement numérique.
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        // Lien complet (ex. https://…/billet/<jeton>) : on garde le dernier segment.
        if (preg_match('#^https?://#i', $raw)) {
            $path = (string) parse_url($raw, PHP_URL_PATH);
            $raw = basename($path);
        }
        if (str_starts_with($raw, self::PREFIX . '.')) {
            $parts = explode('.', $raw);
            if (count($parts) !== 3 || !self::isValidFormat($parts[1])) {
                return ['token' => null, 'signed' => true, 'error' => 'format'];
            }
            if (!hash_equals(self::signature($parts[1]), $parts[2])) {
                return ['token' => $parts[1], 'signed' => true, 'error' => 'signature'];
            }
            return ['token' => $parts[1], 'signed' => true, 'error' => null];
        }
        // Saisie manuelle par groupes (« 7KQ4 2M9X … ») : les espaces ne font jamais partie d'un code. Pour un code uniquement
        // numérique, les tirets de mise en forme sont aussi ignorés.
        $raw = (string) preg_replace('/\s+/', '', $raw);
        if (preg_match('/^[\d-]{6,80}$/', $raw)) {
            $raw = str_replace('-', '', $raw);
        }
        if (!self::isValidFormat($raw)) {
            return ['token' => null, 'signed' => false, 'error' => 'format'];
        }
        return ['token' => $raw, 'signed' => false, 'error' => null];
    }

    /** Aperçu non sensible d'un code pour le journal (jamais le code complet d'un billet inconnu). */
    public static function hint(string $raw): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '', $raw) ?? '';
        if (str_starts_with($clean, self::PREFIX . '.')) {
            $clean = substr($clean, strlen(self::PREFIX) + 1);
        }
        return mb_substr($clean, 0, 4) . (strlen($clean) > 4 ? '…' : '');
    }
}
