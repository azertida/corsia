# Corsia

*Corsia* : « le couloir de nage », en italien.

Une petite appli pour savoir **qui vient au cours** (aquagym, apnée…) dans un cercle d'habitués, sans passer par un groupe de messagerie.

On partage sa présence, pas sa vie :

- pas de numéro de téléphone, pas d'adresse e-mail, pas de compte ;
- pas de chat ni de notifications ;
- un surnom et un animal, rien de plus ;
- aucun historique : tout s'efface après chaque séance.

## Comment ça marche

- **Un cercle** se rejoint par un lien d'invitation qui contient son code. Sans ce code, on ne voit rien.
- **Mes cours** : chacun coche les cours où il va d'habitude. À chaque ouverture, Corsia l'inscrit aux séances correspondantes des 7 prochains jours.
- **Pas cette fois** : on se retire d'une séance sans toucher à ses habitudes.
- **Je viens exceptionnellement** : on s'inscrit à une séance hors de ses habitudes.
- **Je sors de l'eau un moment** : une pause. Corsia arrête les inscriptions automatiques et garde les habitudes pour le retour (« Je replonge »).
- **Propositions** : tout le monde peut proposer un cours. La personne qui gère le cercle le valide.

## Données

| Où | Quoi | Combien de temps |
|---|---|---|
| Le téléphone | code du cercle, surnom, animal, habitudes, jetons de ses inscriptions | jusqu'à ce qu'on les efface |
| Le serveur | liste des cours ; pour chaque séance à venir : surnom, animal, réaction | effacé après la séance |

- Chaque inscription porte un **jeton** que seul le téléphone qui l'a créée connaît. Le serveur n'en garde qu'une empreinte et ne relie pas les inscriptions entre elles d'une semaine à l'autre.
- Le script du serveur n'enregistre ni adresse IP ni autre trace. L'hébergeur tient, comme tout hébergeur, ses propres journaux techniques.
- Il n'y a ni statistiques, ni cookies, ni traceurs.

## Architecture

- **L'appli** : un seul fichier `index.html` (HTML, CSS et JavaScript, sans dépendance), servi par GitHub Pages.
- **Le serveur** : `serveur/api.php` (PHP 8 + SQLite), hébergé chez alwaysdata (France). Il est placé dans `www/corsia/api.php`, et ses données dans `~/corsia-data/`, hors de la partie publique du site.
- Le serveur n'accepte que les requêtes venant de `https://azertida.github.io`.

Ce dépôt contient une copie de `serveur/api.php` pour garder son historique. GitHub Pages ne l'exécute pas : seule la copie déposée chez l'hébergeur est active.

### Actions du serveur

| Action | Qui | Rôle |
|---|---|---|
| `creer_cercle` | libre pour le premier cercle, ensuite clé d'administration requise | crée un cercle |
| `semaine` | membres | cours et inscriptions des 14 prochains jours |
| `inscrire`, `modifier`, `retirer` | membres (jeton) | gérer sa présence à une séance |
| `proposer` | membres (ajout direct pour l'administration) | proposer un cours |
| `valider`, `supprimer_cours` | administration | gérer les cours |

## Mise à jour

- **Appli** : remplacer les fichiers dans ce dépôt. GitHub Pages republie tout seul.
- **Serveur** : redéposer `api.php` chez l'hébergeur (WebDAV). La base se met à jour toute seule.
- **Hébergement gratuit** : se connecter à l'administration alwaysdata au moins une fois tous les 120 jours la première année, sinon le compte est suspendu (une connexion suffit à le rétablir).

## Crédits

- Illustrations des animaux : [Microsoft Fluent Emoji](https://github.com/microsoft/fluentui-emoji), licence MIT (voir `animaux/LICENCE-Fluent-Emoji.txt`).
- Capybara : illustration créée avec ChatGPT, dans le style des précédentes.
- Polices : Fraunces et Atkinson Hyperlegible, via Google Fonts.
