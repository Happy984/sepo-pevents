<?php

declare(strict_types=1);

namespace SepoEvents;

defined('ABSPATH') || exit;

/**
 * Proof of payment: files people attach to an RSVP for a paid event.
 *
 * Files go into a private folder under uploads, one folder per event, with
 * random names. The web server is told not to serve the folder, and files
 * are only handed out through admin-post.php to people who manage events.
 *
 * Accepted: images (JPG, PNG, GIF, WebP), PDF, and documents (Word, OpenDocument,
 * RTF). Each file is checked by its content, not only its name.
 */
final class Proofs
{
    public const DIR = 'sepo-events-private';
    public const MAX_FILES = 5;

    /** @return array<string,string> extension pattern => mime type */
    public static function mimes(): array
    {
        $mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            'pdf'          => 'application/pdf',
            'doc'          => 'application/msword',
            'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'odt'          => 'application/vnd.oasis.opendocument.text',
            'rtf'          => 'application/rtf',
        ];
        return (array) apply_filters('sepo_events_proof_mimes', $mimes);
    }

    /** The extensions people may pick, for the file input and the help text. @return list<string> */
    public static function extensions(): array
    {
        $out = [];
        foreach (array_keys(self::mimes()) as $pattern) {
            foreach (explode('|', (string) $pattern) as $ext) {
                $out[] = strtolower($ext);
            }
        }
        return array_values(array_unique($out));
    }

    /** The accepted types for people to read: "JPG, PNG, …". */
    public static function label(): string
    {
        return strtoupper(implode(', ', array_diff(self::extensions(), ['jpe', 'jpeg'])));
    }

    /** The largest proof file, in megabytes: the setting, never above what the server accepts. */
    public static function maxMb(): int
    {
        $server = max(1, (int) floor(wp_max_upload_size() / 1048576));
        return max(1, min($server, (int) (Settings::get('proof_max_mb') ?: 5)));
    }

    public static function baseDir(): string
    {
        $u = wp_upload_dir(null, false);
        return trailingslashit($u['basedir']) . self::DIR;
    }

    /** Make the private folder and stop the web server listing or serving it. */
    public static function protectDir(): void
    {
        $dir = self::baseDir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $files = [
            '.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        ];
        foreach ($files as $name => $body) {
            if (!is_file($dir . '/' . $name)) {
                @file_put_contents($dir . '/' . $name, $body);
            }
        }
    }

    /**
     * The uploaded files under one field, as a flat list, whether PHP gave
     * them as a single file or as the name[] array shape.
     *
     * @param array<string,mixed> $field
     * @return list<array{name:string,tmp_name:string,error:int,size:int}>
     */
    public static function fromUpload(array $field): array
    {
        if (!isset($field['name'])) {
            return [];
        }
        if (!is_array($field['name'])) {
            return [['name' => (string) $field['name'], 'tmp_name' => (string) $field['tmp_name'], 'error' => (int) $field['error'], 'size' => (int) $field['size']]];
        }
        $out = [];
        foreach ($field['name'] as $i => $name) {
            $out[] = ['name' => (string) $name, 'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''), 'error' => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($field['size'][$i] ?? 0)];
        }
        return array_values(array_filter($out, static fn($f) => $f['error'] !== UPLOAD_ERR_NO_FILE && $f['name'] !== ''));
    }

    /**
     * Check every file first, then store them all. Nothing is kept unless
     * every file passes.
     *
     * @param list<array{name:string,tmp_name:string,error:int,size:int}> $files
     * @return list<array{file:string,name:string,type:string,size:int,at:int}>
     */
    public static function store(int $eventId, array $files, int $already = 0): array
    {
        if ($files === []) {
            return [];
        }
        if (count($files) + $already > self::MAX_FILES) {
            /* translators: %d: number of files */
            throw new \InvalidArgumentException(sprintf(__('Attach at most %d files.', 'sepo-events'), self::MAX_FILES));
        }
        $max = self::maxMb() * 1048576;
        $allowed = self::mimes();
        $checked = [];
        foreach ($files as $f) {
            $name = sanitize_file_name($f['name']);
            if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $max) {
                /* translators: 1: file name, 2: size in MB */
                throw new \InvalidArgumentException(sprintf(__('"%1$s" is larger than %2$d MB.', 'sepo-events'), $name, self::maxMb()));
            }
            if ($f['error'] !== UPLOAD_ERR_OK) {
                /* translators: %s: file name */
                throw new \InvalidArgumentException(sprintf(__('"%s" did not upload. Please try again.', 'sepo-events'), $name));
            }
            if (!is_uploaded_file($f['tmp_name']) && !apply_filters('sepo_events_allow_local_file', false, $f['tmp_name'])) {
                throw new \InvalidArgumentException(__('A file could not be read.', 'sepo-events'));
            }
            $type = wp_check_filetype_and_ext($f['tmp_name'], $f['name'], $allowed);
            if (empty($type['ext']) || empty($type['type'])) {
                /* translators: 1: file name, 2: list of file types */
                throw new \InvalidArgumentException(sprintf(__('"%1$s" is not an accepted file type. Use %2$s.', 'sepo-events'), $name, self::label()));
            }
            $checked[] = $f + ['ext' => (string) $type['ext'], 'type' => (string) $type['type'], 'clean' => $name];
        }

        self::protectDir();
        $dir = self::baseDir() . '/' . $eventId;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $stored = [];
        foreach ($checked as $f) {
            $file = bin2hex(random_bytes(12)) . '.' . $f['ext'];
            if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $file) && !@rename($f['tmp_name'], $dir . '/' . $file)) {
                self::remove($eventId, $stored);
                throw new \RuntimeException(__('A file could not be saved. Please try again.', 'sepo-events'));
            }
            @chmod($dir . '/' . $file, 0640);
            $stored[] = ['file' => $file, 'name' => $f['clean'], 'type' => $f['type'], 'size' => (int) $f['size'], 'at' => time()];
        }
        return $stored;
    }

    /** @param list<array<string,mixed>> $proofs */
    public static function remove(int $eventId, array $proofs): void
    {
        foreach ($proofs as $p) {
            $path = self::baseDir() . '/' . $eventId . '/' . basename((string) ($p['file'] ?? ''));
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function removeEvent(int $eventId): void
    {
        $dir = self::baseDir() . '/' . $eventId;
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($dir);
    }

    /** The manager's link to one proof file. */
    public static function url(string $rsvpId, int $index): string
    {
        return add_query_arg([
            'action'   => 'sepo_events_proof',
            'rsvp'     => $rsvpId,
            'file'     => $index,
            '_wpnonce' => wp_create_nonce('sepo_events_proof'),
        ], admin_url('admin-post.php'));
    }

    /**
     * The files on a record, with links for managers.
     *
     * @param array<string,mixed> $r
     * @return list<array{name:string,type:string,size:string,image:bool,url:string,at:int}>
     */
    public static function listFor(array $r): array
    {
        $out = [];
        foreach ((array) ($r['proofs'] ?? []) as $i => $p) {
            $out[] = [
                'name'  => (string) ($p['name'] ?? ''),
                'type'  => (string) ($p['type'] ?? ''),
                'size'  => size_format((int) ($p['size'] ?? 0)),
                'image' => str_starts_with((string) ($p['type'] ?? ''), 'image/'),
                'url'   => self::url((string) $r['id'], (int) $i),
                'at'    => (int) ($p['at'] ?? 0),
            ];
        }
        return $out;
    }

    public static function hook(): void
    {
        add_action('admin_post_sepo_events_proof', [self::class, 'download']);
        add_action('admin_post_nopriv_sepo_events_proof', [Admin\Tools::class, 'login']);
    }

    /** Hand one proof file to a manager: images and PDFs open in the browser, documents download. */
    public static function download(): void
    {
        if (!Plugin::canManage() || !check_admin_referer('sepo_events_proof')) {
            wp_die(esc_html__('You cannot open this file.', 'sepo-events'), '', ['response' => 403]);
        }
        // phpcs:disable WordPress.Security.NonceVerification -- checked above
        $id = isset($_GET['rsvp']) ? sanitize_text_field(wp_unslash((string) $_GET['rsvp'])) : '';
        $i = isset($_GET['file']) ? absint($_GET['file']) : -1;
        // phpcs:enable
        $r = Rsvps::get($id);
        $p = $r['proofs'][$i] ?? null;
        $path = $r && is_array($p) ? self::baseDir() . '/' . (int) $r['event_id'] . '/' . basename((string) $p['file']) : '';
        if ($path === '' || !is_file($path)) {
            wp_die(esc_html__('The file is no longer available.', 'sepo-events'), '', ['response' => 404]);
        }
        $type = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string) $p['type']) ? (string) $p['type'] : 'application/octet-stream';
        $inline = in_array($type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true);
        nocache_headers();
        header('Content-Type: ' . $type);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', (string) $p['name']) . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        if (str_starts_with($type, 'image/')) {
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        }
        readfile($path);
        exit;
    }
}
