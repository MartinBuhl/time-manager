<?php
/**
 * JSON-API des E-Mail-Clients. Jede Anfrage baut eine eigene IMAP-Verbindung auf.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/mail_client.php';

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();
encSessionRestore();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mcOk($data = null): never
{
    echo json_encode(['success' => true, 'data' => $data], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function mcErr(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ImapException || $e instanceof RuntimeException) {
        mcErr($e->getMessage(), 502);
    }
    error_log('Mail API: ' . $e->getMessage());
    mcErr('Serverfehler: ' . $e->getMessage(), 500);
});

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    mcErr('Nicht angemeldet.', 401);
}
$stmt = db()->prepare('SELECT * FROM tm_users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
if (($user['role'] ?? '') !== 'admin') {
    mcErr('Kein Zugriff.', 403);
}
require_once dirname(__DIR__) . '/includes/i18n.php';
i18nInit(i18nResolve($user['lang'] ?? null, $_SESSION['lang'] ?? null, cfg('default_lang', 'de')));
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    mcErr('Ungültiger Sicherheitstoken.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcErr('Nur POST.', 405);
}

// Die Session wird nicht mehr gebraucht; so blockieren langsame Mailserver die übrige App nicht.
session_write_close();
@set_time_limit(120);

$action = (string)($_POST['action'] ?? '');

/** @return list<int> */
function mcUids(): array
{
    $uids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['uids'] ?? ''))), fn($u) => $u > 0));
    if (!$uids) {
        mcErr('Keine Nachricht ausgewählt.');
    }
    return $uids;
}

function mcFolderParam(string $key = 'folder'): string
{
    $folder = (string)($_POST[$key] ?? '');
    if ($folder === '' || strlen($folder) > 500 || preg_match('/[\r\n\0]/', $folder)) {
        mcErr('Ungültiger Ordner.');
    }
    return $folder;
}

/**
 * Anhänge des Schreibfensters: Verweise auf vorhandene Nachrichten (JSON „keep“) und hochgeladene Dateien.
 *
 * @return array{0: list<array{folder:string, uid:int, part:string}>, 1: list<array{tmp:string, name:string}>}
 */
function mcComposeAttachments(): array
{
    $keep = [];
    $list = json_decode((string)($_POST['keep'] ?? '[]'), true);
    foreach (is_array($list) ? $list : [] as $ref) {
        $folder = (string)($ref['folder'] ?? '');
        $uid = (int)($ref['uid'] ?? 0);
        $part = (string)($ref['part'] ?? '');
        if ($folder === '' || strlen($folder) > 500 || preg_match('/[\r\n\0]/', $folder) || $uid <= 0
            || !preg_match('/^\d+(\.\d+)*$/', $part)) {
            mcErr('Ungültiger Anhang.');
        }
        $keep[] = ['folder' => $folder, 'uid' => $uid, 'part' => $part];
    }
    $uploads = [];
    $files = $_FILES['files'] ?? null;
    if (is_array($files) && is_array($files['name'] ?? null)) {
        foreach ($files['name'] as $i => $name) {
            $err = (int)$files['error'][$i];
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) {
                mcErr('Ein Anhang konnte nicht hochgeladen werden (Fehler ' . $err . ').');
            }
            $name = trim(str_replace(["\r", "\n", "\0", '/', '\\'], ' ', (string)$name)) ?: 'anhang';
            $uploads[] = ['tmp' => $files['tmp_name'][$i], 'name' => mb_substr($name, 0, 200)];
        }
    }
    return [$keep, $uploads];
}

switch ($action) {
    case 'test_imap':
        $settings = mcSettings(array_intersect_key($_POST, array_flip(MC_SETTING_KEYS)));
        $imap = mcImap($settings);
        $folders = mcFolders($imap, false);
        $imap->logout();
        mcOk(['folders' => count($folders), 'names' => array_column($folders, 'display')]);

    case 'test_smtp':
        $settings = mcSettings(array_intersect_key($_POST, array_flip(MC_SETTING_KEYS)));
        mcTestSmtp($settings);
        mcOk();

    case 'folders':
        $imap = mcImap();
        mcOk(['folders' => mcFolders($imap)]);

    case 'list':
        $folder = mcFolderParam();
        $page = max(0, (int)($_POST['page'] ?? 0));
        $query = trim((string)($_POST['q'] ?? ''));
        $imap = mcImap();
        $uids = null;
        if ($query !== '') {
            $imap->select($folder, true);
            $uids = $imap->search(mb_substr($query, 0, 200));
        }
        mcOk(mcListMessages($imap, $folder, $page, $uids));

    case 'message':
        $folder = mcFolderParam();
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid <= 0) {
            mcErr('Ungültige Nachricht.');
        }
        $imap = mcImap();
        $raw = mcFetchRaw($imap, $folder, $uid);
        $msg = MimeMessage::analyze($raw);
        unset($raw);
        $flags = [];
        foreach ($imap->fetch((string)$uid, 'UID FLAGS', true) as $data) {
            $flags = array_map(fn($f) => strtolower((string)$f), (array)($data['FLAGS'] ?? []));
        }
        $wasSeen = in_array('\\seen', $flags, true);
        if (!$wasSeen && ($_POST['mark_seen'] ?? '1') === '1') {
            $imap->select($folder);
            $imap->setFlags([$uid], ['\\Seen'], true);
        }
        $msg['uid'] = $uid;
        $msg['folder'] = $folder;
        $msg['flagged'] = in_array('\\flagged', $flags, true);
        $msg['was_seen'] = $wasSeen;
        mcOk($msg);

    case 'flag':
        $folder = mcFolderParam();
        $uids = mcUids();
        $flag = match ((string)($_POST['flag'] ?? '')) {
            'seen'    => '\\Seen',
            'flagged' => '\\Flagged',
            default   => mcErr('Unbekanntes Flag.'),
        };
        $imap = mcImap();
        $imap->select($folder);
        $imap->setFlags($uids, [$flag], ($_POST['on'] ?? '1') === '1');
        mcOk();

    case 'move':
        $folder = mcFolderParam();
        $target = mcFolderParam('target');
        $uids = mcUids();
        if ($target === $folder) {
            mcOk();
        }
        $imap = mcImap();
        $imap->select($folder);
        $imap->move($uids, $target);
        mcOk();

    case 'delete':
        $folder = mcFolderParam();
        $uids = mcUids();
        $imap = mcImap();
        $trash = mcFolderByRole($imap, 'trash');
        $imap->select($folder);
        if ($trash !== null && $trash !== $folder && ($_POST['permanent'] ?? '') !== '1') {
            $imap->move($uids, $trash);
            mcOk(['moved_to' => $trash]);
        }
        $imap->delete($uids);
        mcOk(['deleted' => count($uids)]);

    case 'compose_source':
        $folder = mcFolderParam();
        $uid = (int)($_POST['uid'] ?? 0);
        $mode = (string)($_POST['mode'] ?? '');
        if ($uid <= 0 || !in_array($mode, ['reply', 'replyall', 'forward', 'edit'], true)) {
            mcErr('Ungültige Anfrage.');
        }
        $imap = mcImap();
        mcOk(mcComposeSource($imap, $folder, $uid, $mode));

    case 'send':
    case 'save_draft':
        $draft = $action === 'save_draft';
        $s = mcSettings();
        [$keep, $uploads] = mcComposeAttachments();
        $warnings = [];
        $imap = null;
        try {
            $imap = mcImap($s);
        } catch (ImapException $e) {
            if ($draft || $keep) {
                throw $e;
            }
            $warnings[] = 'IMAP: ' . $e->getMessage();
        }
        $mail = mcBuildMailer($s, $_POST, $keep, $uploads, $imap, $draft);
        $messageId = $mail->MessageID;

        if ($draft) {
            $drafts = mcFolderByRole($imap, 'drafts');
            if ($drafts === null) {
                mcErr('Kein Entwürfe-Ordner gefunden. Bitte in der Konfiguration eintragen.');
            }
            $raw = mcRenderForStorage($mail);
            $imap->append($drafts, $raw, ['\\Seen', '\\Draft']);
            // Ältere Fassungen erst nach dem erfolgreichen Ablegen entfernen
            $imap->select($drafts);
            $uids = $imap->searchHeader('Message-ID', $messageId);
            $newUid = $uids ? max($uids) : 0;
            $old = array_values(array_filter($uids, fn($u) => $u !== $newUid));
            if ($old) {
                $imap->delete($old);
            }
            $attachments = [];
            if ($newUid) {
                foreach (MimeMessage::analyze($raw)['attachments'] as $a) {
                    $attachments[] = ['folder' => $drafts, 'uid' => $newUid, 'part' => $a['id'],
                        'filename' => $a['filename'], 'size' => $a['size']];
                }
            }
            mcOk(['draft_id' => $messageId, 'folder' => $drafts, 'uid' => $newUid,
                'attachments' => $attachments]);
        }

        try {
            $mail->send();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            mcErr('Senden fehlgeschlagen: ' . $e->getMessage(), 502);
        }

        // Ab hier ist die Nachricht verschickt; Fehler werden nur noch als Hinweis gemeldet.
        if ($imap) {
            if ($s['mc_save_sent'] !== '0') {
                try {
                    $sent = mcFolderByRole($imap, 'sent');
                    if ($sent === null) {
                        $warnings[] = 'Kein Gesendet-Ordner gefunden; die Kopie wurde nicht abgelegt.';
                    } else {
                        $imap->append($sent, mcSentCopy($mail), ['\\Seen']);
                    }
                } catch (ImapException $e) {
                    $warnings[] = 'Ablage in „Gesendet“ fehlgeschlagen: ' . $e->getMessage();
                }
            }
            try {
                $drafts = mcFolderByRole($imap, 'drafts');
                if ($drafts !== null && ($_POST['draft_id'] ?? '') !== '') {
                    mcDeleteDrafts($imap, $drafts, $messageId);
                }
            } catch (ImapException $e) {
                $warnings[] = 'Entwurf konnte nicht entfernt werden: ' . $e->getMessage();
            }
            $origFolder = (string)($_POST['orig_folder'] ?? '');
            $origUid = (int)($_POST['orig_uid'] ?? 0);
            $mode = (string)($_POST['mode'] ?? '');
            if ($origFolder !== '' && $origUid > 0 && in_array($mode, ['reply', 'replyall', 'forward'], true)) {
                try {
                    $imap->select($origFolder);
                    $imap->setFlags([$origUid], [$mode === 'forward' ? '$Forwarded' : '\\Answered'], true);
                } catch (ImapException) {
                    // Manche Server erlauben keine eigenen Schlüsselwörter wie $Forwarded
                }
            }
        }
        mcOk(['warnings' => $warnings]);

    default:
        mcErr('Unbekannte Aktion.');
}
