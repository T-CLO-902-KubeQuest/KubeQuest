# 0001 - Migration de la sample-app vers Laravel 12

- Statut : Accepted
- Date : 2026-06-19
- Décideurs : équipe KubeQuest
- PR : [#62](https://github.com/T-CLO-902-KubeQuest/KubeQuest/pull/62)

## Contexte

La `sample-app` est une application Laravel packagée en image Docker et déployée
via le chart Helm `helm/sample-app`. Le pipeline CI/CD
(`.github/workflows/sample-app-build.yml`) construit l'image, la scanne avec
**Trivy** (`severity: CRITICAL,HIGH`, `ignore-unfixed: true`, `exit-code: 1`),
puis ne publie l'image et le chart que si le scan passe.

L'application tournait sous **Laravel 8**, dont le support a pris fin. Après avoir
réduit l'exposition aux CVE (mise à jour de l'image de base PHP, puis des
dépendances Composer), il restait **une vulnérabilité bloquante** :

- `GHSA-5vg9-5847-vvmq` (HIGH) — injection CRLF dans la règle de validation
  d'e-mail de `laravel/framework` (`v8.83.29`).

Trivy classe cette vulnérabilité comme `fixed` : le correctif n'existe que dans
**Laravel 12 / 13**. `ignore-unfixed: true` ne la filtre donc pas, et
`v8.83.29` est la dernière version de la branche Laravel 8 (EOL) — aucun patch
rétroporté n'est possible. Le pipeline restait rouge tant que cette CVE
subsistait.

## Décision

Migrer la `sample-app` de **Laravel 8 vers Laravel 12** pour corriger la
vulnérabilité à la source plutôt que de la masquer.

L'application n'étant qu'un squelette Laravel par défaut auquel s'ajoute un peu
de code métier (compteur `Counter` : modèle, contrôleur, migration, routes et
vue), la migration a été réalisée en **repartant du squelette Laravel 12 (slim)**
et en y regreffant le code spécifique, plutôt qu'en patchant successivement
Laravel 8 → 9 → 10 → 11 → 12.

Décisions associées :

- Passage du runtime à l'image **`php:8.3-apache`**.
- Ajout d'un `config.platform.php = "8.3"` dans `composer.json` pour que le
  `composer.lock` soit toujours résolu pour la version PHP du runtime (et non
  pour le PHP du poste de développement, ce qui avait déjà cassé un build
  précédent).
- Conservation de **Sanctum** (mis à jour en `^4`) pour les jetons d'API du
  modèle `User`.

## Alternatives envisagées

- **`.trivyignore` documenté** : ignorer explicitement `GHSA-5vg9-5847-vvmq` en
  justifiant que Laravel 8 est EOL. Débloque le pipeline immédiatement et reste
  traçable, mais laisse une vulnérabilité HIGH connue et corrigeable dans
  l'image livrée. Rejeté : on préfère corriger à la source.
- **Rester sur Laravel 8** : non viable, la branche est EOL et ne recevra plus
  de correctifs de sécurité.

## Conséquences

### Positives

- `composer audit` ne remonte plus aucune vulnérabilité ; le gate Trivy passe,
  débloquant la publication de l'image et du chart Helm.
- L'application repose désormais sur une version de Laravel maintenue, sur un
  runtime PHP 8.3 supporté plus longtemps.
- Le `config.platform.php` rend les builds déterministes vis-à-vis de la version
  PHP du runtime.

### Négatives / points d'attention

- Changement de structure majeur : disparition des kernels HTTP/Console, routing
  configuré dans `bootstrap/app.php`, middlewares et providers réorganisés.
- Les routes d'API doivent être activées explicitement
  (`withRouting(api: ...)`) ; `routes/api.php` est passé de la syntaxe historique
  `$router->get(...)` à `Route::get(...)`.
- Le front (Laravel Mix / `webpack.mix.js`) a été retiré ; il n'était pas
  construit par l'image Docker, donc sans impact runtime, mais une éventuelle
  reprise du front devra passer par Vite.
