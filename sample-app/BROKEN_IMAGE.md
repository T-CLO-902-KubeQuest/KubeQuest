# Image cassée — `sample-app:broken`

Variante **volontairement défectueuse** de `sample-app`, destinée à démontrer en
soutenance qu'un déploiement cassé est **détecté par le cluster** (probes,
redémarrages, OOMKill, métriques) et, sous Argo Rollouts, **rollback
automatiquement**.

C'est la **même application** que l'image saine : on n'ajoute qu'un entrypoint
d'injection de panne (`docker/break-entrypoint.sh`) par-dessus l'image publiée.
Le défaut ne s'arme **que** si la variable d'environnement `BREAK_MODE` est
positionnée — sinon l'image se comporte exactement comme la normale.

## Sécurité / isolation

- **Tag dédié** : l'image est publiée uniquement sous `:broken`, jamais sous un
  tag semver ni `latest`.
- **Hors pipeline auto** : `sample-app-build.yml` se déclenche sur `push` ;
  l'image cassée est construite par `sample-app-broken.yml`, en
  **`workflow_dispatch` (manuel) uniquement**. Elle ne peut donc pas partir en
  production via le flux de release normal.
- **Inerte par défaut** : sans `BREAK_MODE`, le conteneur démarre l'appli
  normalement.

## Les trois modes de panne

Un seul mode à la fois (un crash masquerait une fuite ; mélanger les signaux rend
la démo illisible).

| `BREAK_MODE` | Ce que ça casse | Réaction du cluster | Détecté par |
|---|---|---|---|
| `crash` | Le process principal sort en erreur après `BREAK_CRASH_DELAY` s (15 par défaut). | Le kubelet redémarre, échoue encore → `CrashLoopBackOff`. Le pod n'atteint jamais `Ready`. | Redémarrages / état du conteneur ; sous canary, `progressDeadlineSeconds` → `Degraded`. |
| `oom` | Un process fuit ~50 MiB/s jusqu'à dépasser la limite mémoire (512 Mi). | Le kernel OOM-kill le process (PID 1) → conteneur `OOMKilled` + redémarrage, en boucle. | Reason `OOMKilled` + redémarrages ; **courbe mémoire** dans Grafana/Prometheus. |
| `cpu` | Des boucles saturent la limite CPU (500m). | La cgroup est throttlée, Apache est affamé, `/readyz` time out → readinessProbe en échec → pod `NotReady`. | readinessProbe ; **courbe CPU / throttling** dans Grafana/Prometheus. |

> Le mode `cpu` est le moins déterministe : le passage en `NotReady` dépend du
> timing du throttling face au `failureThreshold` de la probe. Le signal le plus
> net pour ce mode est la métrique CPU dans Grafana. `crash` et `oom` sont francs
> et rapides — privilégie-les pour une démo live.

## Construire & publier l'image

### En CI (recommandé, reproductible)

GitHub → onglet **Actions** → workflow *Sample App (BROKEN)* → **Run workflow**
(input `base_tag` = `latest` par défaut). Il construit `Dockerfile.broken` en
amd64+arm64 et pousse `…/sample-app:broken` sur GHCR.

### En local

```sh
# Le contexte de build est le dossier sample-app/ (comme le CI).
IMG=ghcr.io/t-clo-902-kubequest/kubequest/sample-app:broken

# Single-arch (suffisant si tu testes sur ta machine) :
docker build -f sample-app/Dockerfile.broken --build-arg BASE_TAG=latest -t "$IMG" sample-app
docker push "$IMG"

# Multi-arch (si ton cluster a des nœuds arm64) :
docker buildx build -f sample-app/Dockerfile.broken --build-arg BASE_TAG=latest \
  --platform linux/amd64,linux/arm64 -t "$IMG" --push sample-app
```

## Démo autonome — prouver que le cluster détecte le défaut

Le manifeste [`broken-demo.yaml`](./broken-demo.yaml) déploie un `Deployment`
jetable (1 réplica), **sans dépendance MySQL**, avec les mêmes limites de
ressources que la prod. La liveness est un `tcpSocket` (vrai dès qu'Apache
écoute), donc le pod est **sain à l'état inerte** et seul le défaut injecté le
casse. À déployer dans un namespace dédié, à supprimer après.

```sh
kubectl create namespace broken-demo --dry-run=client -o yaml | kubectl apply -f -

# Choisis le mode en éditant la ligne BREAK_MODE du fichier (crash | oom | cpu),
# puis applique :
kubectl apply -n broken-demo -f sample-app/broken-demo.yaml
```

### Observer

```sh
kubectl get pods -n broken-demo -w
# crash -> RESTARTS grimpe, STATUS CrashLoopBackOff
# oom   -> RESTARTS grimpe, dernière raison OOMKilled (voir ci-dessous)
# cpu   -> pod Running mais regarde la métrique CPU dans Grafana

kubectl describe pod -n broken-demo -l app=broken-demo | grep -A3 -iE 'Last State|Reason|Events'
# oom -> "Last State: Terminated, Reason: OOMKilled"

kubectl get events -n broken-demo --sort-by=.lastTimestamp | tail
```

Dans **Grafana**, sur le pod `broken-demo` : la courbe **mémoire** grimpe en
dents de scie jusqu'à la limite (mode `oom`) ; la courbe **CPU** colle à 500m avec
du throttling (mode `cpu`).

### Nettoyer

```sh
kubectl delete namespace broken-demo
```

## Intégrer au rollback canary (étape suivante)

La démo ci-dessus prouve la **détection**. Pour enchaîner sur le **rollback
automatique** déjà câblé (`helm/sample-app`, voir
[`ROLLBACK_DEMO.md`](../helm/sample-app/ROLLBACK_DEMO.md)), il faut déployer cette
image comme nouvelle révision du `Rollout` avec `BREAK_MODE` armé. Le chart
expose l'image via `image.repository`/`image.tag` mais **ne propage pas encore**
`BREAK_MODE` dans l'env du conteneur. Le brancher proprement demande un petit
ajout au chart (une valeur `app.breakMode` émise comme variable d'env dans
`templates/rollout.yaml`) — volontairement laissé hors de ce ticket, qui ne livre
que l'image et sa doc.

En attendant, le levier `app.forceUnhealthy` (cf. `ROLLBACK_DEMO.md`) reste la
voie en place pour la démo canary de bout en bout.
