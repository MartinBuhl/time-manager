<?php
/**
 * Mehrsprachigkeit (i18n).
 *
 * Sprachdateien liegen unter lang/<code>.php und geben ein Array
 * ['key' => 'Text'] zurück. Deutsch (de) ist die Basis/Quelle; andere
 * Sprachen werden darüber gelegt, sodass fehlende Übersetzungen
 * automatisch auf Deutsch zurückfallen. So bleibt das System jederzeit
 * auslieferbar, auch wenn noch nicht alles übersetzt ist.
 *
 * Nutzung:
 *   i18nInit($lang);            // einmal pro Request
 *   echo t('app.save');         // Text der aktiven Sprache
 *   echo t('greet', ['name'=>$n]); // mit Platzhaltern {name}
 */

/** Verfügbare Sprachen (Reihenfolge = Anzeige in Umschaltern). */
function i18nAvailableLangs(): array
{
    return ['de', 'en'];
}

/** Anzeigenamen der Sprachen. */
function i18nLangLabels(): array
{
    return ['de' => 'Deutsch', 'en' => 'English'];
}

function i18nLoadFile(string $lang): array
{
    $file = dirname(__DIR__) . '/lang/' . $lang . '.php';
    if (is_file($file)) {
        $arr = require $file;
        return is_array($arr) ? $arr : [];
    }
    return [];
}

/** Bereinigt/validiert einen Sprachcode gegen die verfügbaren Sprachen. */
function i18nNormalize(?string $lang, string $default = 'de'): string
{
    $langs = i18nAvailableLangs();
    if ($lang !== null && in_array($lang, $langs, true)) {
        return $lang;
    }
    return in_array($default, $langs, true) ? $default : 'de';
}

/**
 * Ermittelt die aktive Sprache aus (Prio): explizit übergeben →
 * Benutzer-Einstellung → Session → globaler Default → 'de'.
 */
function i18nResolve(?string $userLang, ?string $sessionLang, string $default): string
{
    foreach ([$userLang, $sessionLang, $default] as $cand) {
        if ($cand !== null && $cand !== '' && in_array($cand, i18nAvailableLangs(), true)) {
            return $cand;
        }
    }
    return 'de';
}

function i18nInit(?string $lang = null): void
{
    global $I18N_LANG, $I18N_STRINGS;
    $I18N_LANG    = i18nNormalize($lang);
    $base         = i18nLoadFile('de');
    $I18N_STRINGS = $I18N_LANG === 'de'
        ? $base
        : array_merge($base, i18nLoadFile($I18N_LANG));
}

function currentLang(): string
{
    global $I18N_LANG;
    return $I18N_LANG ?? 'de';
}

/** Gibt alle geladenen Strings zurück (z. B. für den JSON-Export an JS). */
function i18nStrings(): array
{
    global $I18N_STRINGS;
    return $I18N_STRINGS ?? [];
}

/**
 * Übersetzt einen Schlüssel. Fehlt er, wird (Deutsch-Basis oder) der
 * Schlüssel selbst zurückgegeben. Platzhalter {name} werden ersetzt.
 */
function t(string $key, array $params = []): string
{
    global $I18N_STRINGS;
    $s = $I18N_STRINGS[$key] ?? $key;
    foreach ($params as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

/**
 * Übersetzt einen Schlüssel in einer bestimmten Sprache, ohne die aktive
 * Sprache des Requests zu verändern. Nützlich z. B. für E-Mails an einen
 * Empfänger mit eigener Sprach-Einstellung. Deutsch bleibt Basis/Fallback.
 */
function tLang(string $key, ?string $lang, array $params = []): string
{
    static $cache = [];
    $lang = i18nNormalize($lang);
    if (!isset($cache[$lang])) {
        $base = i18nLoadFile('de');
        $cache[$lang] = $lang === 'de' ? $base : array_merge($base, i18nLoadFile($lang));
    }
    $s = $cache[$lang][$key] ?? $key;
    foreach ($params as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}
