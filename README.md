# Corsia

*Corsia* : « le couloir de nage », en italien.

Une petite appli pour savoir **qui vient au cours** (cours en piscine : aqua, apnée…) dans un cercle d'habitués, sans passer par un groupe de messagerie.

On partage sa présence, pas sa vie :

- pas de numéro de téléphone, pas d'adresse e-mail, pas de compte ;
- pas de chat ni de notifications ;
- un surnom et un animal, rien de plus ;
- aucun historique : tout s'efface après chaque séance.

## Comment ça marche

- **Un cercle** se rejoint par un lien d'invitation qui contient son code. Sans ce code, on ne voit rien.
- **Tout se décide dans la semaine**, sur la carte de chaque séance :
  - pour entrer : **Cette fois** (une seule séance) ou **Chaque jeudi** (« Un vendredi sur deux ») ;
  - ensuite, trois réglages qui montrent chacun leur état :
    - **J'y vais | Pas cette fois** pour cette séance ;
    - un interrupteur **Chaque jeudi** pour l'habitude : allumé, Corsia montre au cercle sa présence aux séances correspondantes des 7 prochains jours (Corsia ne réserve rien au club) ; éteint, une séance déjà prévue le reste ;
    - **Un petit mot ?** (les réactions), quand on y va.
- **Je sors de l'eau un moment** : une pause. Corsia arrête les inscriptions automatiques et garde les habitudes pour le retour (« Je replonge »).
- **Réactions** : sur une séance où l'on va, un petit mot facultatif : 🙌 J'ai hâte et ⏰ Un peu en retard (cumulables), ou 🤷 Peut-être pas (seule).
- **Mes cours / Tous les cours** : l'écran de la semaine montre par défaut ses propres cours.
- **Inviter** : un lien à partager, ou un QR code à faire scanner sur place.
- **Installer Corsia** : proposé une fois, à la deuxième ouverture, avec les gestes pour iPhone et Android ; toujours accessible en bas de la semaine.
- **Lien de secours** (dans son profil) : contient le cercle, le surnom, l'animal et les habitudes — jamais la clé de gestion. Il restaure tout sur un appareil qui a oublié, et sert de pont vers l'appli installée sur iPhone (où Safari et l'appli installée ne partagent pas leurs données).
- **Ce que Corsia garde** : la page qui explique, en clair, ce qui est conservé et où, avec le bouton « Tout effacer de ce téléphone ».

### Pour la personne qui gère le cercle

- **Les cours** : l'écran où elle tient à jour le programme complet du club. Les membres ne le voient pas ; il apparaît dès que la clé de gestion est présente sur l'appareil.

- **Rythme d'un cours** : chaque semaine, une semaine sur deux (à partir de la date de la prochaine séance : rien n'apparaît avant), ou séance unique (un cours spécial, qui disparaît après sa date).
- **Interrompre un cours** : un jour ou une période (vacances d'un·e prof…). Les séances apparaissent barrées, « Pas de cours ».
- **Fermetures du club** : un jour ou une période où tous les cours sont supprimés (« Club fermé »).
- Dans les deux cas, les inscriptions déjà faites sur ces séances sont retirées ; les habitudes restent et reprennent d'elles-mêmes.
- **Clé de gestion** : remise une seule fois à la création du cercle. Le serveur n'en garde qu'une empreinte : elle ne peut pas être retrouvée. Sur un autre appareil, « Mon profil › Je gère ce cercle » (tout en bas) permet de la saisir.

## Données

| Où | Quoi | Combien de temps |
|---|---|---|
| Le téléphone | code du cercle, surnom, animal, habitudes, jetons de ses inscriptions | jusqu'à ce qu'on les efface |
| Le serveur | liste des cours et des interruptions ; pour chaque séance à venir : surnom, animal, réaction | inscriptions et interruptions effacées une fois passées ; séances uniques aussi |

- Une même personne (même surnom, même animal) n'apparaît qu'**une fois par séance** : en cas de réinstallation ou de deuxième appareil, la nouvelle inscription remplace l'ancienne.
- Chaque inscription porte un **jeton** que seul le téléphone qui l'a créée connaît. Le serveur n'en garde qu'une empreinte et ne relie pas les inscriptions entre elles d'une semaine à l'autre.
- Le script du serveur n'enregistre ni adresse IP ni autre trace. L'hébergeur tient, comme tout hébergeur, ses propres journaux techniques.
- Il n'y a ni statistiques, ni cookies, ni traceurs. Les polices sont hébergées avec l'appli : aucun appel à Google ni à un autre service.

## Architecture

- **L'appli** : `index.html` (HTML, CSS et JavaScript), servi par GitHub Pages, avec :
  - `manifest.webmanifest` et `icones/` pour l'installation sur l'écran d'accueil (pas de `start_url` : sur iPhone, l'icône reprend l'adresse affichée au moment de l'ajout, qui contient alors le lien de secours) ;
  - `sw.js`, qui met l'appli en cache — **augmenter `CACHE_NAME` à chaque mise à jour** ;
  - `qrcode.js` pour dessiner le QR code, sans appel extérieur ;
  - `polices/` et `animaux/`.
- Le numéro de version (`VERSION` dans `index.html`) s'affiche en bas de « Les cours » et de « Ce que Corsia garde ».
- **Le serveur** : `serveur/api.php` (PHP 8 + SQLite), hébergé chez alwaysdata (France). Il est placé dans `www/corsia/api.php`, et ses données dans `~/corsia-data/`, hors de la partie publique du site.
- Le serveur n'accepte que les requêtes venant de `https://azertida.github.io`.

Ce dépôt contient une copie de `serveur/api.php` pour garder son historique. GitHub Pages ne l'exécute pas : seule la copie déposée chez l'hébergeur est active.

### Actions du serveur

| Action | Qui | Rôle |
|---|---|---|
| `creer_cercle` | libre pour le premier cercle, ensuite clé d'administration requise | crée un cercle |
| `semaine` | membres | cours, interruptions et inscriptions des 14 prochains jours (avec la clé : propositions en plus) |
| `inscrire`, `modifier`, `retirer` | membres (jeton) | gérer sa présence à une séance |
| `proposer` | administration (ajout direct) — l'appli ne propose plus cette action aux membres | ajouter un cours |
| `valider`, `supprimer_cours` | administration | gérer les cours (`valider` n'est plus utilisé par l'appli) |
| `interrompre`, `annuler_interruption` | administration | interrompre un cours ou fermer le club, du … au … |

## Mise à jour

- **Appli** : remplacer les fichiers dans ce dépôt. GitHub Pages republie tout seul.
- **Serveur** : redéposer `api.php` chez l'hébergeur (WebDAV). La base se met à jour toute seule.
- **Hébergement gratuit** : se connecter à l'administration alwaysdata au moins une fois tous les 120 jours la première année, sinon le compte est suspendu (une connexion suffit à le rétablir).

## Crédits

- Illustrations des animaux : [Microsoft Fluent Emoji](https://github.com/microsoft/fluentui-emoji), licence MIT (voir `animaux/LICENCE-Fluent-Emoji.txt`).
- Capybara, raie manta et hippocampe : illustrations créées avec ChatGPT, dans le style des précédentes.
- Polices : Fraunces et Atkinson Hyperlegible, licence SIL OFL 1.1 (voir `polices/`).
- QR code : [qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator) de Kazuhiko Arase, licence MIT (mention dans `qrcode.js`).
- Icône : inspirée de l'icône de l'appli Croisement.
