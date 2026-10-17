<?php
/*
 * Corsia — petit serveur des inscriptions (version 2)
 *
 * Principes :
 *  - un « cercle » = un code secret partagé par lien ; sans code, on ne voit rien ;
 *  - une inscription = une séance précise, avec un jeton que seul le téléphone
 *    qui l'a créée connaît (le serveur n'en garde qu'une empreinte) ;
 *  - aucune fiche de personne, aucun historique : les inscriptions des séances
 *    passées sont effacées automatiquement ;
 *  - aucune adresse IP ni autre trace n'est enregistrée par ce script.
 *
 * Nouveautés de la version 2 :
 *  - rythme des cours : chaque semaine, une semaine sur deux, séance unique ;
 *  - interruptions d'un cours (jour ou période) et fermetures du club ;
 *  - vérification d'une clé d'administration saisie sur un autre appareil.
 *
 * Emplacement : www/corsia/api.php — les données vivent hors du site,
 * dans ~/corsia-data/corsia.sqlite (créé et mis à jour automatiquement).
 */

date_default_timezone_set('Europe/Brussels');

const ORIGINES_AUTORISEES = ['https://azertida.github.io'];
const ANIMAUX = ['poulpe', 'capybara', 'loutre', 'tortue', 'meduse', 'dauphin',
                 'phoque', 'manchot', 'baleine', 'grenouille', 'poisson-globe', 'crabe',
                 'raie', 'hippocampe'];
const REACTIONS = ['', 'hate', 'retard', 'peutetre'];
const FENETRE_JOURS = 14;          // on accepte les inscriptions jusqu'à 14 jours à l'avance
const HORIZON_JOURS = 400;         // une interruption ou une séance unique : au plus un an à l'avance
const MAX_SURNOM = 20;
const MAX_NOM_COURS = 30;
const MAX_COURS_PAR_CERCLE = 40;
const MAX_PROPOSITIONS = 10;
const MAX_INTERRUPTIONS = 60;
const MAX_INSCRITS_PAR_SEANCE = 60;

/* ---------- En-têtes ---------- */

$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origine, ORIGINES_AUTORISEES, true)) {
    header('Access-Control-Allow-Origin: ' . $origine);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ---------- Outils ---------- */

function repondre($donnees, int $code = 200): void {
    http_response_code($code);
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE);
    exit;
}

function erreur(string $message, int $code = 400): void {
    repondre(['erreur' => $message], $code);
}

function aleatoire(int $octets): string {
    return bin2hex(random_bytes($octets));
}

function empreinte(string $jeton): string {
    return hash('sha256', $jeton);
}

function texte($valeur, int $max): string {
    $t = trim(preg_replace('/\s+/u', ' ', (string)($valeur ?? '')));
    $t = preg_replace('/[\x00-\x1F\x7F]/u', '', $t);
    if (mb_strlen($t) > $max) $t = mb_substr($t, 0, $max);
    return $t;
}

function aujourdhui(): string { return date('Y-m-d'); }
function dans(int $jours): string { return date('Y-m-d', strtotime("+$jours days")); }

function verifier_date($d, string $quoi = 'Date'): string {
    $d = (string)($d ?? '');
    $o = DateTime::createFromFormat('!Y-m-d', $d);
    if (!$o || $o->format('Y-m-d') !== $d) erreur("$quoi invalide");
    return $d;
}

function jour_iso(string $date): int {
    return (int)DateTime::createFromFormat('!Y-m-d', $date)->format('N');
}

function ecart_jours(string $a, string $b): int {
    $da = DateTime::createFromFormat('!Y-m-d', $a);
    $db = DateTime::createFromFormat('!Y-m-d', $b);
    return (int)round(($db->getTimestamp() - $da->getTimestamp()) / 86400);
}

function colonnes(PDO $db, string $table): array {
    return array_column($db->query("PRAGMA table_info($table)")->fetchAll(), 'name');
}

function base(): PDO {
    $dossier = dirname(__DIR__, 2) . '/corsia-data';
    if (!is_dir($dossier) && !mkdir($dossier, 0700, true)) {
        erreur('Stockage indisponible', 500);
    }
    $db = new PDO('sqlite:' . $dossier . '/corsia.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA busy_timeout = 3000');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('
        CREATE TABLE IF NOT EXISTS cercles (
            code        TEXT PRIMARY KEY,
            admin_hash  TEXT NOT NULL,
            nom         TEXT NOT NULL DEFAULT \'\'
        );
        CREATE TABLE IF NOT EXISTS cours (
            id      TEXT PRIMARY KEY,
            cercle  TEXT NOT NULL REFERENCES cercles(code) ON DELETE CASCADE,
            nom     TEXT NOT NULL,
            jour    INTEGER NOT NULL,      -- 1 = lundi … 7 = dimanche
            heure   TEXT NOT NULL,         -- "19:00"
            statut  TEXT NOT NULL          -- "ok" ou "propose"
        );
        CREATE TABLE IF NOT EXISTS inscriptions (
            id          TEXT PRIMARY KEY,
            cercle      TEXT NOT NULL REFERENCES cercles(code) ON DELETE CASCADE,
            cours       TEXT NOT NULL REFERENCES cours(id) ON DELETE CASCADE,
            date        TEXT NOT NULL,     -- "2026-10-22"
            jeton_hash  TEXT NOT NULL,
            surnom      TEXT NOT NULL,
            animal      TEXT NOT NULL,
            reaction    TEXT NOT NULL DEFAULT \'\'
        );
        CREATE INDEX IF NOT EXISTS idx_insc ON inscriptions(cercle, date);
        CREATE TABLE IF NOT EXISTS interruptions (
            id      TEXT PRIMARY KEY,
            cercle  TEXT NOT NULL REFERENCES cercles(code) ON DELETE CASCADE,
            cours   TEXT REFERENCES cours(id) ON DELETE CASCADE,   -- vide = fermeture du club
            du      TEXT NOT NULL,
            au      TEXT NOT NULL
        );
    ');
    // Mise à jour d'une base de la version 1 : rythme et date de référence des cours.
    $cols = colonnes($db, 'cours');
    if (!in_array('rythme', $cols, true)) $db->exec('ALTER TABLE cours ADD COLUMN rythme INTEGER NOT NULL DEFAULT 1'); // 1 = hebdo, 2 = une semaine sur deux, 0 = séance unique
    if (!in_array('ref', $cols, true))    $db->exec('ALTER TABLE cours ADD COLUMN ref TEXT');                          // une date où le cours a lieu

    // Effacement automatique de tout ce qui est passé.
    $auj = aujourdhui();
    $db->prepare('DELETE FROM inscriptions WHERE date < ?')->execute([$auj]);
    $db->prepare('DELETE FROM interruptions WHERE au < ?')->execute([$auj]);
    $db->prepare('DELETE FROM cours WHERE rythme = 0 AND ref < ?')->execute([$auj]);
    return $db;
}

function cercle(PDO $db, string $code): array {
    $st = $db->prepare('SELECT * FROM cercles WHERE code = ?');
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c) erreur('Cercle inconnu', 404);
    return $c;
}

function est_admin(array $cercle, $cle): bool {
    return is_string($cle) && $cle !== '' && hash_equals($cercle['admin_hash'], empreinte($cle));
}

function exiger_admin(array $cercle, array $entree): void {
    if (!est_admin($cercle, $entree['admin'] ?? '')) erreur('Réservé à la personne qui gère le cercle', 403);
}

function cours_du_cercle(PDO $db, string $cercle, string $id): array {
    $st = $db->prepare('SELECT * FROM cours WHERE id = ? AND cercle = ?');
    $st->execute([$id, $cercle]);
    $c = $st->fetch();
    if (!$c) erreur('Cours inconnu', 404);
    return $c;
}

function verifier_heure($h): string {
    if (!is_string($h) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $h)) erreur('Heure invalide');
    return $h;
}

function verifier_jour($j): int {
    $j = (int)$j;
    if ($j < 1 || $j > 7) erreur('Jour invalide');
    return $j;
}

/* Le cours a-t-il lieu à cette date, d'après son rythme ? */
function a_lieu(array $c, string $date): bool {
    $r = (int)$c['rythme'];
    if ($r === 0) return $date === $c['ref'];
    if (jour_iso($date) !== (int)$c['jour']) return false;
    if ($r === 2 && $c['ref']) return ecart_jours($c['ref'], $date) % 14 === 0;
    return true;
}

/* La séance est-elle supprimée (interruption du cours ou fermeture du club) ? */
function interrompu(PDO $db, string $cercle, string $cours, string $date): bool {
    $st = $db->prepare('SELECT 1 FROM interruptions WHERE cercle = ? AND (cours IS NULL OR cours = ?) AND du <= ? AND au >= ? LIMIT 1');
    $st->execute([$cercle, $cours, $date, $date]);
    return (bool)$st->fetchColumn();
}

function cours_publics(PDO $db, string $code, string $statut): array {
    $st = $db->prepare('SELECT id, nom, jour, heure, rythme, ref FROM cours WHERE cercle = ? AND statut = ? ORDER BY rythme = 0, jour, heure');
    $st->execute([$code, $statut]);
    return array_map(function ($c) { $c['jour'] = (int)$c['jour']; $c['rythme'] = (int)$c['rythme']; return $c; }, $st->fetchAll());
}

/* Lit nom, jour, heure, rythme et date de référence d'un cours envoyé par l'appli. */
function lire_cours(array $e): array {
    $nom = texte($e['nom'] ?? '', MAX_NOM_COURS);
    if ($nom === '') erreur('Nom du cours manquant');
    $heure = verifier_heure($e['heure'] ?? '');
    $rythme = (int)($e['rythme'] ?? 1);
    if (!in_array($rythme, [0, 1, 2], true)) erreur('Rythme inconnu');
    $ref = null;
    if ($rythme === 1) {
        $jour = verifier_jour($e['jour'] ?? 0);
    } else {
        $ref = verifier_date($e['ref'] ?? '', 'Date du cours');
        if ($rythme === 0 && ($ref < aujourdhui() || $ref > dans(HORIZON_JOURS))) erreur('Date hors de portée');
        $jour = jour_iso($ref);
    }
    return [$nom, $jour, $heure, $rythme, $ref];
}

/* ---------- Lecture de la requête ---------- */

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$entree = [];
if ($methode === 'POST') {
    $brut = file_get_contents('php://input', false, null, 0, 8192);
    $entree = json_decode($brut ?: '[]', true);
    if (!is_array($entree)) erreur('Requête illisible');
} elseif ($methode !== 'GET') {
    erreur('Méthode non prise en charge', 405);
}
$action = $methode === 'GET' ? ($_GET['action'] ?? '') : ($entree['action'] ?? '');
$code   = $methode === 'GET' ? ($_GET['c'] ?? '')      : ($entree['c'] ?? '');

try {
    $db = base();

    /* ----- Créer un cercle -----
       Le tout premier cercle peut être créé librement.
       Ensuite, il faut la clé d'administration d'un cercle existant. */
    if ($action === 'creer_cercle' && $methode === 'POST') {
        $nb = (int)$db->query('SELECT COUNT(*) FROM cercles')->fetchColumn();
        if ($nb > 0) {
            $ok = false;
            $cle = $entree['cle_existante'] ?? '';
            if (is_string($cle) && $cle !== '') {
                $st = $db->prepare('SELECT 1 FROM cercles WHERE admin_hash = ?');
                $st->execute([empreinte($cle)]);
                $ok = (bool)$st->fetchColumn();
            }
            if (!$ok) erreur('Création réservée', 403);
        }
        $nouveau = aleatoire(9);
        $admin = aleatoire(16);
        $db->prepare('INSERT INTO cercles (code, admin_hash, nom) VALUES (?, ?, ?)')
           ->execute([$nouveau, empreinte($admin), texte($entree['nom'] ?? '', 40)]);
        repondre(['c' => $nouveau, 'admin' => $admin]);
    }

    if (!is_string($code) || !preg_match('/^[0-9a-f]{18}$/', $code)) erreur('Code de cercle manquant', 400);
    $cer = cercle($db, $code);

    switch ($action) {

        /* ----- Lire : cours, interruptions et inscriptions à venir ----- */
        case 'semaine':
            $auj = aujourdhui();
            $st = $db->prepare('SELECT id, cours, date, surnom, animal, reaction FROM inscriptions
                                WHERE cercle = ? AND date BETWEEN ? AND ? ORDER BY rowid');
            $st->execute([$code, $auj, dans(FENETRE_JOURS)]);
            $inscriptions = $st->fetchAll();
            $st = $db->prepare('SELECT id, cours, du, au FROM interruptions WHERE cercle = ? ORDER BY du');
            $st->execute([$code]);
            $rep = ['nom' => $cer['nom'], 'cours' => cours_publics($db, $code, 'ok'),
                    'interruptions' => $st->fetchAll(), 'inscriptions' => $inscriptions];
            if (est_admin($cer, $_GET['admin'] ?? '')) {
                $rep['admin'] = true;
                $rep['propositions'] = cours_publics($db, $code, 'propose');
            }
            repondre($rep);

        /* ----- S'inscrire à une séance ----- */
        case 'inscrire':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            $c = cours_du_cercle($db, $code, (string)($entree['cours'] ?? ''));
            if ($c['statut'] !== 'ok') erreur('Cours pas encore validé');
            $date = verifier_date($entree['date'] ?? '');
            if ($date < aujourdhui() || $date > dans(FENETRE_JOURS)) erreur('Date hors de la fenêtre');
            if (!a_lieu($c, $date)) erreur('Ce cours n\'a pas lieu ce jour-là');
            if (interrompu($db, $code, $c['id'], $date)) erreur('Pas de cours ce jour-là');
            $surnom = texte($entree['surnom'] ?? '', MAX_SURNOM);
            if ($surnom === '') erreur('Surnom manquant');
            $animal = (string)($entree['animal'] ?? '');
            if (!in_array($animal, ANIMAUX, true)) erreur('Animal inconnu');
            $reaction = (string)($entree['reaction'] ?? '');
            if (!in_array($reaction, REACTIONS, true)) erreur('Réaction inconnue');
            $st = $db->prepare('SELECT COUNT(*) FROM inscriptions WHERE cours = ? AND date = ?');
            $st->execute([$c['id'], $date]);
            if ((int)$st->fetchColumn() >= MAX_INSCRITS_PAR_SEANCE) erreur('Séance complète dans Corsia');
            $id = aleatoire(8);
            $jeton = aleatoire(16);
            $db->prepare('INSERT INTO inscriptions (id, cercle, cours, date, jeton_hash, surnom, animal, reaction)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$id, $code, $c['id'], $date, empreinte($jeton), $surnom, $animal, $reaction]);
            repondre(['id' => $id, 'jeton' => $jeton]);

        /* ----- Modifier sa réaction (ou son surnom / animal) ----- */
        case 'modifier':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            $st = $db->prepare('SELECT * FROM inscriptions WHERE id = ? AND cercle = ?');
            $st->execute([(string)($entree['id'] ?? ''), $code]);
            $i = $st->fetch();
            if (!$i || !hash_equals($i['jeton_hash'], empreinte((string)($entree['jeton'] ?? '')))) erreur('Inscription introuvable', 404);
            $reaction = array_key_exists('reaction', $entree) ? (string)$entree['reaction'] : $i['reaction'];
            if (!in_array($reaction, REACTIONS, true)) erreur('Réaction inconnue');
            $surnom = array_key_exists('surnom', $entree) ? texte($entree['surnom'], MAX_SURNOM) : $i['surnom'];
            if ($surnom === '') erreur('Surnom manquant');
            $animal = array_key_exists('animal', $entree) ? (string)$entree['animal'] : $i['animal'];
            if (!in_array($animal, ANIMAUX, true)) erreur('Animal inconnu');
            $db->prepare('UPDATE inscriptions SET reaction = ?, surnom = ?, animal = ? WHERE id = ?')
               ->execute([$reaction, $surnom, $animal, $i['id']]);
            repondre(['ok' => true]);

        /* ----- Se retirer d'une séance ----- */
        case 'retirer':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            $st = $db->prepare('SELECT jeton_hash FROM inscriptions WHERE id = ? AND cercle = ?');
            $st->execute([(string)($entree['id'] ?? ''), $code]);
            $h = $st->fetchColumn();
            if ($h && hash_equals($h, empreinte((string)($entree['jeton'] ?? '')))) {
                $db->prepare('DELETE FROM inscriptions WHERE id = ?')->execute([(string)$entree['id']]);
            }
            repondre(['ok' => true]);

        /* ----- Proposer un cours (membres) ou l'ajouter directement (gestion) ----- */
        case 'proposer':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            [$nom, $jour, $heure, $rythme, $ref] = lire_cours($entree);
            $admin = est_admin($cer, $entree['admin'] ?? '');
            $st = $db->prepare('SELECT COUNT(*) FROM cours WHERE cercle = ? AND statut = ?');
            $st->execute([$code, 'ok']);
            if ((int)$st->fetchColumn() >= MAX_COURS_PAR_CERCLE) erreur('Trop de cours dans ce cercle');
            if (!$admin) {
                $st->execute([$code, 'propose']);
                if ((int)$st->fetchColumn() >= MAX_PROPOSITIONS) erreur('Déjà beaucoup de propositions en attente');
            }
            $id = aleatoire(6);
            $db->prepare('INSERT INTO cours (id, cercle, nom, jour, heure, statut, rythme, ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([$id, $code, $nom, $jour, $heure, $admin ? 'ok' : 'propose', $rythme, $ref]);
            repondre(['id' => $id, 'statut' => $admin ? 'ok' : 'propose']);

        /* ----- Valider ou refuser une proposition (gestion) ----- */
        case 'valider':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            exiger_admin($cer, $entree);
            $c = cours_du_cercle($db, $code, (string)($entree['cours'] ?? ''));
            if (!empty($entree['accepte'])) {
                $db->prepare('UPDATE cours SET statut = ? WHERE id = ?')->execute(['ok', $c['id']]);
            } else {
                $db->prepare('DELETE FROM cours WHERE id = ?')->execute([$c['id']]);
            }
            repondre(['ok' => true]);

        /* ----- Supprimer un cours (gestion) : ses inscriptions partent avec lui ----- */
        case 'supprimer_cours':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            exiger_admin($cer, $entree);
            $c = cours_du_cercle($db, $code, (string)($entree['cours'] ?? ''));
            $db->prepare('DELETE FROM cours WHERE id = ?')->execute([$c['id']]);
            repondre(['ok' => true]);

        /* ----- Interrompre un cours, ou fermer le club (sans cours), du … au … (gestion) ----- */
        case 'interrompre':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            exiger_admin($cer, $entree);
            $cours = null;
            if (!empty($entree['cours'])) $cours = cours_du_cercle($db, $code, (string)$entree['cours'])['id'];
            $du = verifier_date($entree['du'] ?? '', 'Date de début');
            $au = verifier_date($entree['au'] ?? $du, 'Date de fin');
            if ($au < $du) erreur('La fin est avant le début');
            if ($au < aujourdhui()) erreur('Cette période est déjà passée');
            if ($du > dans(HORIZON_JOURS)) erreur('Date hors de portée');
            $st = $db->prepare('SELECT COUNT(*) FROM interruptions WHERE cercle = ?');
            $st->execute([$code]);
            if ((int)$st->fetchColumn() >= MAX_INTERRUPTIONS) erreur('Trop d\'interruptions enregistrées');
            $id = aleatoire(6);
            $db->prepare('INSERT INTO interruptions (id, cercle, cours, du, au) VALUES (?, ?, ?, ?, ?)')
               ->execute([$id, $code, $cours, $du, $au]);
            // Les inscriptions déjà faites sur les séances supprimées disparaissent.
            if ($cours) {
                $db->prepare('DELETE FROM inscriptions WHERE cercle = ? AND cours = ? AND date BETWEEN ? AND ?')->execute([$code, $cours, $du, $au]);
            } else {
                $db->prepare('DELETE FROM inscriptions WHERE cercle = ? AND date BETWEEN ? AND ?')->execute([$code, $du, $au]);
            }
            repondre(['id' => $id]);

        /* ----- Annuler une interruption (gestion) ----- */
        case 'annuler_interruption':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            exiger_admin($cer, $entree);
            $db->prepare('DELETE FROM interruptions WHERE id = ? AND cercle = ?')->execute([(string)($entree['id'] ?? ''), $code]);
            repondre(['ok' => true]);

        default:
            erreur('Action inconnue');
    }
} catch (Throwable $e) {
    erreur('Erreur du serveur', 500);
}
