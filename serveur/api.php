<?php
/*
 * Corsia — petit serveur des inscriptions
 *
 * Principes :
 *  - un « cercle » = un code secret partagé par lien ; sans code, on ne voit rien ;
 *  - une inscription = une séance précise, avec un jeton que seul le téléphone
 *    qui l'a créée connaît (le serveur n'en garde qu'une empreinte) ;
 *  - aucune fiche de personne, aucun historique : les inscriptions des séances
 *    passées sont effacées automatiquement ;
 *  - aucune adresse IP ni autre trace n'est enregistrée par ce script.
 *
 * Emplacement : www/corsia/api.php — les données vivent hors du site,
 * dans ~/corsia-data/corsia.sqlite (créé automatiquement).
 */

date_default_timezone_set('Europe/Brussels');

const ORIGINES_AUTORISEES = ['https://azertida.github.io'];
const ANIMAUX = ['poulpe', 'capybara', 'loutre', 'tortue', 'meduse', 'dauphin',
                 'phoque', 'manchot', 'baleine', 'grenouille', 'poisson-globe', 'crabe',
                 'raie', 'hippocampe'];
const REACTIONS = ['', 'hate', 'retard', 'peutetre'];
const FENETRE_JOURS = 14;          // on accepte les inscriptions jusqu'à 14 jours à l'avance
const MAX_SURNOM = 20;
const MAX_NOM_COURS = 30;
const MAX_COURS_PAR_CERCLE = 30;
const MAX_PROPOSITIONS = 10;
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
    ');
    // Effacement automatique : tout ce qui concerne une séance passée disparaît.
    $db->prepare('DELETE FROM inscriptions WHERE date < ?')->execute([date('Y-m-d')]);
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
       Le tout premier cercle peut être créé librement (c'est le tien).
       Ensuite, il faut la clé d'administration d'un cercle existant,
       pour éviter que n'importe qui en crée. */
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

        /* ----- Lire la semaine : cours et inscriptions à venir ----- */
        case 'semaine':
            $st = $db->prepare('SELECT id, nom, jour, heure FROM cours WHERE cercle = ? AND statut = ? ORDER BY jour, heure');
            $st->execute([$code, 'ok']);
            $cours = $st->fetchAll();
            $fin = date('Y-m-d', strtotime('+' . FENETRE_JOURS . ' days'));
            $st = $db->prepare('SELECT id, cours, date, surnom, animal, reaction FROM inscriptions
                                WHERE cercle = ? AND date BETWEEN ? AND ? ORDER BY rowid');
            $st->execute([$code, date('Y-m-d'), $fin]);
            $rep = ['nom' => $cer['nom'], 'cours' => $cours, 'inscriptions' => $st->fetchAll()];
            if (est_admin($cer, $_GET['admin'] ?? '')) {
                $st = $db->prepare('SELECT id, nom, jour, heure FROM cours WHERE cercle = ? AND statut = ?');
                $st->execute([$code, 'propose']);
                $rep['propositions'] = $st->fetchAll();
            }
            repondre($rep);

        /* ----- S'inscrire à une séance ----- */
        case 'inscrire':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            $c = cours_du_cercle($db, $code, (string)($entree['cours'] ?? ''));
            if ($c['statut'] !== 'ok') erreur('Cours pas encore validé');
            $date = (string)($entree['date'] ?? '');
            $d = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) erreur('Date invalide');
            if ($date < date('Y-m-d') || $date > date('Y-m-d', strtotime('+' . FENETRE_JOURS . ' days'))) erreur('Date hors de la fenêtre');
            if ((int)$d->format('N') !== (int)$c['jour']) erreur('Ce cours n\'a pas lieu ce jour-là');
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
            // Réponse identique si l'inscription n'existe plus : retirer deux fois n'est pas une erreur.
            if ($h && hash_equals($h, empreinte((string)($entree['jeton'] ?? '')))) {
                $db->prepare('DELETE FROM inscriptions WHERE id = ?')->execute([(string)$entree['id']]);
            }
            repondre(['ok' => true]);

        /* ----- Proposer un cours (tout le cercle) ou l'ajouter directement (admin) ----- */
        case 'proposer':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            $nom = texte($entree['nom'] ?? '', MAX_NOM_COURS);
            if ($nom === '') erreur('Nom du cours manquant');
            $jour = verifier_jour($entree['jour'] ?? 0);
            $heure = verifier_heure($entree['heure'] ?? '');
            $admin = est_admin($cer, $entree['admin'] ?? '');
            $st = $db->prepare('SELECT COUNT(*) FROM cours WHERE cercle = ? AND statut = ?');
            $st->execute([$code, 'ok']);
            if ((int)$st->fetchColumn() >= MAX_COURS_PAR_CERCLE) erreur('Trop de cours dans ce cercle');
            if (!$admin) {
                $st->execute([$code, 'propose']);
                if ((int)$st->fetchColumn() >= MAX_PROPOSITIONS) erreur('Déjà beaucoup de propositions en attente');
            }
            $id = aleatoire(6);
            $db->prepare('INSERT INTO cours (id, cercle, nom, jour, heure, statut) VALUES (?, ?, ?, ?, ?, ?)')
               ->execute([$id, $code, $nom, $jour, $heure, $admin ? 'ok' : 'propose']);
            repondre(['id' => $id, 'statut' => $admin ? 'ok' : 'propose']);

        /* ----- Valider ou refuser une proposition (admin) ----- */
        case 'valider':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            if (!est_admin($cer, $entree['admin'] ?? '')) erreur('Réservé à l\'administration du cercle', 403);
            $c = cours_du_cercle($db, $code, (string)($entree['cours'] ?? ''));
            if (!empty($entree['accepte'])) {
                $db->prepare('UPDATE cours SET statut = ? WHERE id = ?')->execute(['ok', $c['id']]);
            } else {
                $db->prepare('DELETE FROM cours WHERE id = ?')->execute([$c['id']]);
            }
            repondre(['ok' => true]);

        /* ----- Supprimer un cours (admin) : ses inscriptions partent avec lui ----- */
        case 'supprimer_cours':
            if ($methode !== 'POST') erreur('POST attendu', 405);
            if (!est_admin($cer, $entree['admin'] ?? '')) erreur('Réservé à l\'administration du cercle', 403);
            $c = cours_du_cercle($db, $code, (string)($entree['cours'] ?? ''));
            $db->prepare('DELETE FROM cours WHERE id = ?')->execute([$c['id']]);
            repondre(['ok' => true]);

        default:
            erreur('Action inconnue');
    }
} catch (Throwable $e) {
    erreur('Erreur du serveur', 500);
}
