# composer-roast

**English.** One PHP file, zero dependencies. It reads a `composer.lock` and scores the versions you actually pinned: abandoned packages, ancient pins, missing licenses. The last line is meant to be pasted in a PR.

```
composer-roast 32/100 (compost). 4 abandoned, 5 ancient, oldest: fideloper/proxy 4.4.2 (7.6y).
```

**Français.** Un seul fichier PHP, aucune dépendance. Il lit un `composer.lock` et note les versions épinglées : paquets abandonnés, versions trop vieilles, licences absentes. La dernière ligne est faite pour un ticket ou une PR.

Ce n'est pas un scan de CVE. Pour les failles connues, garde `composer audit`. Ici on mesure la **rouille du lock**, pas la popularité d'upstream : un paquet encore maintenu, mais épinglé sur une release de 2019, compte comme ancien.

## Lancer

PHP 8.1+ et rien d'autre.

```bash
php composer-roast.php
php composer-roast.php path/vers/composer.lock
php composer-roast.php --json
php composer-roast.php --fail-under=70
```

Codes de sortie : `0` rapport ok, `1` score sous `--fail-under`, `2` fichier ou arguments invalides.

| Option | Rôle |
| --- | --- |
| `--json` | Un objet JSON sur stdout |
| `--now=YYYY-MM-DD` | Fige « aujourd'hui » en UTC (sortie reproductible) |
| `--stale-days=N` | Vieux à partir de N jours (défaut 730, ~2 ans) |
| `--ancient-days=N` | Ancien à partir de N jours (défaut 1460, ~4 ans) |
| `--fail-under=N` | Échoue si le score est **strictement** inférieur à N (0–100) |
| `--no-color` | Pas de couleur. `NO_COLOR` est aussi respecté |

## Exemple

`fixtures/demo.lock` est un lock **d'illustration**, figé au 2026-10-25. Les dates ne décrivent pas l'état actuel de ces projets. Lance l'outil sur **ton** lock.

```bash
php composer-roast.php fixtures/demo.lock --now=2026-10-25
```

```
composer-roast
lock: fixtures/demo.lock
as of: 2026-10-25T00:00:00Z
scored versions pinned in the lock, not upstream popularity

packages: 12 (10 prod / 2 dev)
score: 32/100  compost

abandoned      4
ancient        5    >= 1460d
stale          5    >= 730d
undated        0
no license     0
median age     1004.5d (2.8y)

oldest         fideloper/proxy  4.4.2  2764d (7.6y)

fossils
   2764d    7.6y  fideloper/proxy               4.4.2  abandoned -> illuminate/http
   2732d    7.5y  psr/log                       1.1.4
   1850d    5.1y  doctrine/annotations          1.13.3  abandoned
   1810d    5.0y  swiftmailer/swiftmailer       v6.3.0  abandoned -> symfony/mailer
   1537d    4.2y  doctrine/cache                2.2.0  abandoned -> psr/cache

abandoned
  doctrine/annotations@1.13.3  no replacement
  doctrine/cache@2.2.0  -> psr/cache
  fideloper/proxy@4.4.2  -> illuminate/http
  swiftmailer/swiftmailer@v6.3.0  -> symfony/mailer

share this
  composer-roast 32/100 (compost). 4 abandoned, 5 ancient, oldest: fideloper/proxy 4.4.2 (7.6y).
```

## Le score

100, moins un malus. `n` est le nombre d'entrées (`packages` + `packages-dev`).

```
malus = min(45, 15 × abandonnés)
      + round(30 × vieux / n)
      + round(25 × anciens / n)
      + round(10 × sans date / n)
      + min(10, 2 × sans licence)
score = max(0, 100 − malus)
```

- **Abandonné** : le champ `abandoned` du lock vaut `true` ou le nom du remplaçant Packagist.
- **Ancien** : `time` à au moins `--ancient-days` (défaut 1460).
- **Vieux** : au moins `--stale-days` (défaut 730), mais pas encore ancien.
- **Sans date** : pas de `time`, ou une date illisible.
- **Sans licence** : champ absent, vide, ou liste vide.
- Une date future compte pour 0 jour. Sans fuseau, le temps est lu en UTC.

| Score | Grade |
| ---: | --- |
| 90–100 | pristine |
| 75–89 | healthy |
| 55–74 | rusty |
| 30–54 | compost |
| 0–29 | archaeological |

Le malus d'abandon est plafonné à 45, celui des licences à 10. Un lock vide vaut 100 : il n'y a rien à rouiller.

## CI

```yaml
- name: Roast the lockfile
  run: php composer-roast.php --fail-under=70
```

`--json` convient si tu veux archiver le détail. Le programme ne contacte aucun réseau et n'exécute rien qui viendrait du lock : il ne fait que lire le JSON.

## Tests

```bash
php tests/roast_test.php
```

## Licence

MIT.
