# Démo — déploiement canary & rollback automatique

Ce guide décrit comment démontrer, en live, le **rollback automatique** d'un
déploiement cassé de `sample-app` (issue #21, Bloc 3).

L'application est déployée comme un **`Rollout` Argo Rollouts** en stratégie
canary. Une nouvelle révision est d'abord déployée comme canary (1 pod sur 2) ;
un `AnalysisRun` interroge l'endpoint **`/readyz`** de l'application en HTTP
(adossé à la base de données). S'il échoue, le `Rollout` est **aborté et
rollback automatiquement** vers la version stable précédente.

## Pré-requis

- Accès au cluster (`kubectl`), contexte pointant sur le cluster KubeQuest.
- Le controller **Argo Rollouts** installé (`argocd/apps/argo-rollouts`,
  sync-wave -1) et l'app `sample-app` `Synced / Healthy`.
- Le **plugin `kubectl-argo-rollouts`** — il n'est pas requis pour le rollback
  lui-même (automatique), mais indispensable pour visualiser l'état et pour
  **relancer le rollout après la démo** (cf. étape 3) :

  ```sh
  # Linux amd64 ; adapter l'arch (arm64) au besoin
  curl -sSL -o ~/.local/bin/kubectl-argo-rollouts \
    https://github.com/argoproj/argo-rollouts/releases/latest/download/kubectl-argo-rollouts-linux-amd64
  chmod +x ~/.local/bin/kubectl-argo-rollouts
  kubectl argo rollouts version
  ```

Variables utilisées ci-dessous :

```sh
NS=sample-app
RO=sample-app-sample-app   # <release>-<chart> => nom du Rollout
```

## Vue d'ensemble live (à garder ouvert pendant la démo)

```sh
kubectl argo rollouts get rollout "$RO" -n "$NS" --watch
```

> Le **dashboard** web est aussi déployé (`argo-rollouts-dashboard`) si on
> préfère une vue graphique : `kubectl argo rollouts dashboard -n argo-rollouts`.

## Étape 0 — état sain de départ

Vérifier que tout est vert avant de commencer :

```sh
kubectl argo rollouts get rollout "$RO" -n "$NS"      # Status: ✔ Healthy, Step 2/2
kubectl get application sample-app -n argocd \
  -o jsonpath='{.status.sync.status}/{.status.health.status}{"\n"}'   # Synced/Healthy
```

## Étape 1 — casser le déploiement (GitOps)

On force la sonde `/readyz` à échouer via le flag `app.forceUnhealthy`, sans avoir à
construire une image volontairement cassée (l'image reste la même). Éditer
`argocd/apps/sample-app/values.yaml` :

```yaml
app:
  forceUnhealthy: true   # était false
```

Commiter et pousser sur `main`. Argo CD (selfHeal) synchronise le changement,
ce qui crée une nouvelle révision du `Rollout`.

> Pour aller plus vite que le polling : `kubectl annotate application sample-app
> -n argocd argocd.argoproj.io/refresh=normal --overwrite`.

## Étape 2 — observer le rollback automatique

Dans la vue `--watch`, on voit :

1. Un **canary** apparaît (1 pod nouvelle révision) ; son `/readyz` renvoie 503,
   il reste `0/1` `Ready`.
2. L'**`AnalysisRun` `app-health`** s'exécute : le provider web appelle `/readyz`,
   reçoit `{"healthy":false}` (503) ; après `failureLimit`, l'`AnalysisRun` passe
   **`Failed`**.
3. Le `Rollout` est **aborté** et **revient à la version stable**.

Preuves en ligne de commande :

```sh
kubectl get analysisrun -n "$NS" --sort-by=.metadata.creationTimestamp | tail -1   # ... Failed
kubectl describe rollout "$RO" -n "$NS" | sed -n '/Events:/,$p' | grep -E 'AnalysisRunFailed|RolloutAborted'
# RolloutAborted ... Metric "app-health" assessed Failed ...

kubectl get rollout "$RO" -n "$NS" \
  -o jsonpath='{"abort: "}{.status.abort}{"  phase: "}{.status.phase}{"\n"}'        # abort: true  phase: Degraded
```

**Service maintenu** — la version stable continue de servir, sans interruption :

```sh
kubectl get rs -n "$NS" -o custom-columns=NAME:.metadata.name,DESIRED:.spec.replicas,READY:.status.readyReplicas
# seul le ReplicaSet stable est à 2/2 ; les canary sont à 0
```

## Étape 3 — réparer et relancer

1. Remettre le flag à `false` dans `argocd/apps/sample-app/values.yaml` :

   ```yaml
   app:
     forceUnhealthy: false
   ```

   Commiter / pousser (et éventuellement forcer un refresh Argo CD).

2. **⚠️ Important** — le flag `abort` du `Rollout` est *collant* : repasser
   `forceUnhealthy: false` ne suffit pas à relancer un canary sain, le `Rollout`
   reste `Degraded`. Lever l'abort explicitement :

   ```sh
   kubectl argo rollouts retry rollout "$RO" -n "$NS"
   ```

   Le canary sain est alors créé, l'`AnalysisRun` réussit (`canary pods ready:
   `/readyz` = `{"healthy":true}`) et le `Rollout` est promu → **`✔ Healthy`**.

```sh
kubectl argo rollouts get rollout "$RO" -n "$NS"   # Status: ✔ Healthy, Step 2/2, weight 100
```

## Comment ça marche (rappel)

| Brique | Rôle |
|---|---|
| `templates/rollout.yaml` | `Rollout` canary ; readiness sur `/readyz`, liveness sur `/` |
| `routes/web.php` (sample-app) | `/readyz` : JSON `{"healthy":…}`, vérifie la DB et honore `APP_FORCE_UNHEALTHY` |
| `templates/analysistemplate.yaml` | `AnalysisRun` : provider **web** appelant `/readyz` (pas de Job, pas de RBAC, pas de Prometheus) |
| `app.forceUnhealthy` (valeur Helm) | levier de démo : force `/readyz` à renvoyer 503 |

Voir aussi [`BEST_PRACTICES.md`](./BEST_PRACTICES.md) et l'ADR
[`0004-argo-rollouts-canary-deployment.md`](../../adr/0004-argo-rollouts-canary-deployment.md).
