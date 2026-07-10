# kuard — workload de démo monitoring

Déploie [kuard](https://github.com/kubernetes-up-and-running/kuard) (l'app de
démo de *Kubernetes Up and Running*) via un `Rollout` Argo Rollouts, avec des
limits volontairement serrées. Synchronisé par Argo CD depuis ce repo
(`argocd/apps/kuard/application.yaml`, namespace `kuard`), exposé sur
<https://kuard.kubequest.epitech.beer>.

Dashboard Grafana associé : **« Demo kuard - OOM / CPU limits / rollback »**
(uid `kuard-demo`, provisionné par `k8s/monitoring/kuard-dashboard.yaml`).

## Démo 1 — OOMKill et relance automatique

1. Ouvrir le dashboard Grafana (rangée « Mémoire vs limit ») à côté de l'UI kuard.
2. Dans kuard, onglet **Memory** (`/mem`) : allouer de la mémoire par blocs
   jusqu'à dépasser la limit de 128 Mi (≈ 2×128 MiB suffisent).
3. Le kernel OOMKill le conteneur : le pod passe brièvement en
   `CrashLoopBackOff` puis est relancé par le kubelet. Sur le dashboard :
   working set qui monte jusqu'à la limit, stat « Pods OOMKilled » à 1,
   restarts qui s'incrémentent.

## Démo 2 — CPU limit et throttling

1. Dans kuard, onglet **KeyGen Workload** : activer le workload (génération de
   clés SSH en boucle).
2. Sur le dashboard : l'usage CPU plafonne à la limit (200m) et le panel
   « CPU throttling » monte vers 100 % — le conteneur est bridé par CFS, pas
   tué.
3. Désactiver le workload pour revenir à la normale.

## Démo 3 — Rollback automatique (forceUnhealthy)

Même mécanisme que `helm/sample-app` : un canary cassé échoue l'analyse et
Argo Rollouts revient au ReplicaSet stable.

1. Dans `helm/kuard/values.yaml`, passer `forceUnhealthy: true`, committer sur
   `main` : Argo CD sync, le Rollout démarre un canary dont la readinessProbe
   pointe sur un chemin inexistant (404) — il ne devient jamais Ready.
2. L'`AnalysisTemplate` sonde le Service canary (`kuard-kuard-canary`), sans
   endpoint Ready chaque mesure échoue ; après 2 échecs le Rollout **abort** et
   rollback vers la version stable (`kubectl argo rollouts get rollout
   kuard-kuard -n kuard --watch` pour le suivi).
3. Remettre `forceUnhealthy: false` et committer.

Variante visuelle sans casser quoi que ce soit : changer `image.tag` de `blue`
à `green` — le canary sain est promu et la bannière de l'UI change de couleur.
