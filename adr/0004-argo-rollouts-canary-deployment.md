# 0004 - Déploiement canary et rollback automatique avec Argo Rollouts

- Statut : Accepted
- Date : 2026-06-19
- Décideurs : équipe KubeQuest
- PR : [#76](https://github.com/T-CLO-902-KubeQuest/KubeQuest/pull/76)

## Contexte

Le Bloc 3 impose un déploiement automatisé qui **vérifie l'état applicatif réel**
et **rollback automatiquement** sur un déploiement cassé, le tout démontrable en
live devant le jury (issue #21).

Avant ce changement, la `sample-app` (Laravel 12) était déployée via un
`Deployment` Helm classique (RollingUpdate implicite) dont les sondes
liveness/readiness tapaient `GET /`. Deux limites :

- `GET /` ne reflète pas l'état réel de l'application : la page d'accueil répond
  même si la base de données est injoignable, donc la readiness valide « le
  process est vivant », pas « l'app fonctionne ».
- Le RollingUpdate natif bloque la bascule si les nouveaux pods ne deviennent pas
  Ready, mais ne fournit **aucun rollback automatique observable** : il faut un
  `kubectl rollout undo` manuel ou un re-pin du chart.

Par ailleurs, le cluster n'embarque **pas de stack Prometheus**, ce qui exclut
une analyse de déploiement basée sur des métriques HTTP (taux de 5xx, latence).

## Décision

### Stratégie de déploiement : Argo Rollouts en canary

La `sample-app` est livrée comme un `Rollout` Argo Rollouts (et non plus un
`Deployment`), en stratégie **canary** : un pod de la nouvelle version
(`setWeight: 50` sur `replicaCount: 2`) sert le trafic en parallèle de la version
stable, puis est promu à 100 % uniquement si l'analyse réussit.

Le controller Argo Rollouts est ajouté à l'app-of-apps comme manifest pré-rendu
(`k8s/argo-rollouts/argo-rollouts.yaml`, valeurs dans `helm/argo-rollouts/`), en
**sync-wave `-1`** pour que les CRDs `Rollout`/`AnalysisTemplate` existent avant
que `sample-app` (wave 1) ne crée son `Rollout`. Même pattern vendoré +
`ServerSideApply=true` que cert-manager et mysql.

### Critère de succès : santé HTTP `/readyz` (sans Prometheus)

Faute de Prometheus, l'analyse repose sur un `AnalysisTemplate` en provider
**`web`** qui interroge directement l'endpoint **`/readyz`** de l'application
(`http://<svc>/readyz`, JSON `{"healthy": …}`). Si `/readyz` répond
`{"healthy": true}` (200), le canary est promu ; s'il échoue (503), au-delà du
`failureLimit` l'`AnalysisRun` passe `Failed`, le `Rollout` est **aborté et
rollback automatiquement** vers le ReplicaSet stable.

> Note d'implémentation : une première version calculait le ratio de pods Ready
> via un `Job` `kubectl` + RBAC. Cette approche s'est révélée fragile (image
> kubectl à maintenir, verdict basé sur l'exit-code du Job, et un `failureLimit`
> strict faisant échouer un canary sain sur une mesure transitoire). Le provider
> `web` sur `/readyz` la remplace : pas de `Job`, pas de RBAC, et le verdict est
> le vrai signal de santé applicative.

### Sondes honnêtes : `/readyz` adossé à la base

La readiness pointe sur `/readyz` (`routes/web.php`), qui ouvre une vraie
connexion à la base et renvoie du JSON. Un canary dont la base est injoignable
n'atteint jamais Ready et est rollback ; le même endpoint sert de critère à
l'`AnalysisRun`. La liveness reste sur `/` à dessein : l'adosser à la base
provoquerait un redémarrage en cascade de tous les pods en cas de hoquet MySQL.

### Démonstration : flag `app.forceUnhealthy`

Pour démontrer le rollback sans builder d'image volontairement cassée (le scan
Trivy de la CI bloquerait une image vulnérable), un flag Helm `app.forceUnhealthy`
expose une variable d'env `APP_FORCE_UNHEALTHY` qui force `/readyz` à répondre
503. La démo se fait en GitOps : passer `forceUnhealthy: true` dans l'Application
`sample-app` puis sync → le canary échoue → rollback automatique observable ;
repasser à `false` (puis `kubectl argo rollouts retry`) pour récupérer.

## Alternatives envisagées

- **Deployment natif + selfHeal Argo CD** : plus simple, mais le « rollback »
  reste manuel (`rollout undo` / re-pin) et peu démonstratif. Écarté car ne
  satisfait pas le critère « rollback automatique observable ».
- **Analyse via métriques Prometheus** (taux de 5xx, latence) : plus réaliste,
  mais nécessite de déployer et maintenir une stack monitoring absente du cluster.
  Écarté pour le scope actuel ; le ratio de pods Ready suffit à détecter une image
  cassée.
- **`progressDeadlineSeconds` seul (sans AnalysisTemplate)** : un canary jamais
  Ready finit `Degraded` après le délai, sans `Job` ni RBAC. Écarté car le
  rollback est moins « actif » et moins visible qu'un `AnalysisRun` `Failed`.
- **Image cassée re-pinnée pour la démo** : plus réaliste, mais nécessite un build
  hors CI et reste moins reproductible que le flag de configuration. Conservé comme
  illustration d'un cas réel, mais pas comme mécanisme principal de démo.

## Conséquences

### Positives

- Rollback automatique et observable (`kubectl argo rollouts`, UI Argo CD), aligné
  sur les critères d'acceptation de l'issue #21.
- La readiness reflète l'état applicatif réel (base de données), pas seulement la
  vivacité du process.
- Aucune dépendance Prometheus ; l'analyse interroge directement la santé HTTP de
  l'application (`/readyz`).

### Négatives / points d'attention

- Nouveau composant à opérer (controller Argo Rollouts, CRDs).
- `progressDeadlineSeconds` doit rester supérieur à la somme des pauses canary,
  sinon un canary sain mais lent serait faussement marqué `Degraded`.
- L'`AnalysisRun` web cible le `Service` (qui fronte tous les pods) ; cela suffit
  ici car `forceUnhealthy` / une image cassée affectent tous les pods, mais ne
  distingue pas un pod canary isolément.
- Après un rollback, le flag `abort` du `Rollout` est *collant* : il faut un
  `kubectl argo rollouts retry rollout` pour relancer un canary sain.
- Sur un bootstrap de cluster *from scratch*, Argo CD ne sérialise pas la
  synchronisation entre Applications : `sample-app` peut tenter d'appliquer son
  `Rollout` avant que les CRDs ne soient présents. La synchronisation converge par
  retry ; pour un bootstrap propre, pré-appliquer le controller Argo Rollouts en
  premier.
