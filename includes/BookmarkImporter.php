<?php
declare(strict_types=1);

/**
 * Importiert Lesezeichen in die Tabelle tm_bookmarks.
 *
 * Unterstützte Formate:
 *   - Firefox-JSON-Sicherung (übernommen wird die Lesezeichen-Symbolleiste,
 *     root = toolbarFolder).
 *   - Netscape-Bookmark-HTML (bookmarks.html) – das browserübergreifende
 *     Exportformat von Chrome, Edge, Brave, Opera, Safari und Firefox.
 *
 * Nutzung (CLI):
 *   php includes/BookmarkImporter.php import/meine-bookmarks.json
 */
class BookmarkImporter
{
    /** @return array{folders:int,links:int} */
    public static function importFromFile(PDO $pdo, string $path, bool $replace = true): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Import-Datei nicht lesbar: ' . $path);
        }

        if ($replace) {
            $pdo->exec('DELETE FROM tm_bookmarks');
        }

        $counts = ['folders' => 0, 'links' => 0];

        // Top-Level-Sortierung fortführen (nach dem Ersetzen 0, sonst hinten anhängen)
        $topStart = (int) $pdo->query(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tm_bookmarks WHERE parent_id IS NULL'
        )->fetchColumn();
        $sortCache = ['null' => $topStart];

        $ins = $pdo->prepare(
            'INSERT INTO tm_bookmarks (parent_id, type, title, url, sort_order) VALUES (?, ?, ?, ?, ?)'
        );

        $trim = ltrim($raw);
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            self::importJson($pdo, $ins, $raw, $counts, $sortCache);
        } else {
            self::importNetscapeHtml($pdo, $ins, $raw, $counts, $sortCache);
        }

        if ($counts['folders'] === 0 && $counts['links'] === 0) {
            throw new RuntimeException('Keine Lesezeichen in der Datei gefunden.');
        }
        return $counts;
    }

    /** Nächster sort_order-Wert innerhalb eines Parents. */
    private static function nextSort(?int $parentId, array &$sortCache): int
    {
        $k = $parentId === null ? 'null' : (string) $parentId;
        if (!isset($sortCache[$k])) {
            $sortCache[$k] = 0;
        }
        return $sortCache[$k]++;
    }

    private static function validUrl(string $u): bool
    {
        if ($u === '') {
            return false;
        }
        foreach (['place:', 'about:', 'view-source:', 'javascript:', 'data:'] as $bad) {
            if (stripos($u, $bad) === 0) {
                return false;
            }
        }
        return true;
    }

    // ---- Firefox-JSON ---------------------------------------------------

    private static function importJson(PDO $pdo, PDOStatement $ins, string $raw, array &$counts, array &$sortCache): void
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Ungültiges JSON in der Import-Datei.');
        }
        $toolbar = self::findRoot($data, 'toolbarFolder');
        if ($toolbar === null) {
            throw new RuntimeException('Keine Lesezeichen-Symbolleiste (toolbarFolder) gefunden.');
        }
        self::insertJsonChildren($pdo, $ins, $toolbar['children'] ?? [], null, $counts, $sortCache);
    }

    private static function findRoot(array $node, string $root): ?array
    {
        if (($node['root'] ?? '') === $root) {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                $found = self::findRoot($child, $root);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private static function insertJsonChildren(PDO $pdo, PDOStatement $ins, array $children, ?int $parentId, array &$counts, array &$sortCache): void
    {
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $isFolder = strpos($child['type'] ?? '', 'container') !== false;
            if ($isFolder) {
                $title = trim((string) ($child['title'] ?? '')) ?: 'Ordner';
                $ins->execute([$parentId, 'folder', mb_substr($title, 0, 500), null, self::nextSort($parentId, $sortCache)]);
                $counts['folders']++;
                $fid = (int) $pdo->lastInsertId();
                self::insertJsonChildren($pdo, $ins, $child['children'] ?? [], $fid, $counts, $sortCache);
            } else {
                $url = (string) ($child['uri'] ?? '');
                if (!self::validUrl($url)) {
                    continue;
                }
                $title = trim((string) ($child['title'] ?? '')) ?: $url;
                $ins->execute([$parentId, 'link', mb_substr($title, 0, 500), $url, self::nextSort($parentId, $sortCache)]);
                $counts['links']++;
            }
        }
    }

    // ---- Netscape-HTML (browserübergreifend) ----------------------------

    private static function importNetscapeHtml(PDO $pdo, PDOStatement $ins, string $html, array &$counts, array &$sortCache): void
    {
        // Alle relevanten Tokens in Dokumentreihenfolge einsammeln.
        $pattern = '~<a\s[^>]*?href\s*=\s*"([^"]*)"[^>]*>(.*?)</a>|<h3\b[^>]*>(.*?)</h3>|<dl\b|</dl>~is';
        if (!preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return;
        }

        $parentStack = [null];   // aktueller Ordner-Kontext (id oder null)
        $pending     = null;     // zuletzt deklarierter Ordner, dessen <DL> noch folgt

        foreach ($matches as $tok) {
            $full = $tok[0];
            if (strncasecmp($full, '<a', 2) === 0) {
                $url   = self::htmlDecode($tok[1]);
                $title = trim(self::htmlDecode(strip_tags($tok[2])));
                if (!self::validUrl($url)) {
                    continue;
                }
                if ($title === '') { $title = $url; }
                $parent = end($parentStack) ?: null;
                $ins->execute([$parent, 'link', mb_substr($title, 0, 500), $url, self::nextSort($parent, $sortCache)]);
                $counts['links']++;
            } elseif (strncasecmp($full, '<h3', 3) === 0) {
                $title  = trim(self::htmlDecode(strip_tags($tok[3]))) ?: 'Ordner';
                $parent = end($parentStack) ?: null;
                $ins->execute([$parent, 'folder', mb_substr($title, 0, 500), null, self::nextSort($parent, $sortCache)]);
                $pending = (int) $pdo->lastInsertId();
                $counts['folders']++;
            } elseif (strncasecmp($full, '</dl', 4) === 0) {
                if (count($parentStack) > 1) {
                    array_pop($parentStack);
                }
            } else { // <dl : Kinder des zuletzt deklarierten Ordners beginnen
                if ($pending !== null) {
                    $parentStack[] = $pending;
                    $pending = null;
                }
            }
        }
    }

    private static function htmlDecode(string $s): string
    {
        return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

// CLI-Einstieg: php includes/BookmarkImporter.php <pfad> [append]
if (PHP_SAPI === 'cli' && isset($argv[1]) && realpath($argv[0]) === realpath(__FILE__)) {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/db.php';
    $replace = !(isset($argv[2]) && $argv[2] === 'append');
    $res = BookmarkImporter::importFromFile(db(), $argv[1], $replace);
    fwrite(STDOUT, "Import fertig: {$res['folders']} Ordner, {$res['links']} Links.\n");
}
