# Démo — auto-scaling applicatif sous charge

Ce guide décrit comment démontrer, en live, le **scaling horizontal automatique**
de `sample-app` sous charge CPU (issue #24, Delivery).

L'application tourne comme un **`Rollout` Argo Rollouts** (2 replicas au repos). Un
**`HorizontalPodAutoscaler`** surveille la conso CPU des pods via la **Metrics
API** (`metrics-server`) et ajuste le nombre de replicas entre `2` et `6` pour
maintenir une utilisation CPU moyenne de **50 %** de la request.

Le scaling agit sur le sous-resource `/scale` du `Rollout` : Argo Rollouts
répartit les replicas entre stable et canary comme le ferait un Deployment.

## Architecture (2 briques, séparées volontairement)

| Brique | Où | Rôle |
|---|---|---|
| `metrics-server` | `k8s/metrics-server/` + `argocd/apps/metrics-server` (wave 0) | expose `metrics.k8s.io` → alimente `kubectl top` **et** l'HPA en CPU/RAM réels |
| HPA (overlay) | `k8s/sample-app-hpa/hpa.yaml` + `argocd/apps/sample-app-autoscaling` (wave 2) | politique de scaling, **séparée** du chart publié sur OCI |

> **Pourquoi l'HPA n'est pas dans le chart ?** Le chart `sample-app` est un
> artefact réutilisable publié sur GHCR. La politique d'autoscaling (min/max,
> seuil) est une décision propre à *cet* environnement → elle vit dans le repo
> GitOps du cluster, pas dans l'artefact. On ajuste le scaling sans republier
> une version du chart, et le chart reste déployable ailleurs sans traîner une
> config d'autoscaling.

## Pré-requis

- Cluster démarré. **Le cluster AWS est auto-stoppé par les admins** — le lancer
  avant la démo :

  ```sh
  ./scripts/0000-start-ec2-instances.sh
  # puis, si Tailscale ne remonte pas, tunnel SSH vers le control-plane :
  #   IP=$(aws ec2 describe-instances \
  #     --filters Name=tag:Group,Values=group-41 Name=tag:Role,Values=control-plane \
  #       Name=instance-state-name,Values=running \
  #     --query "Reservations[].Instances[].PublicIpAddress" --output text)
  #   ssh -i ~/.ssh/kubequest.pem -N -L 6443:127.0.0.1:6443 ec2-user@"$IP" &
  #   kubectl config use-context kubequest-aws
  ```

- Apps `metrics-server` et `sample-app-autoscaling` **Synced / Healthy** dans Argo CD.
- `metrics-server` opérationnel — **le vrai test** :

  ```sh
  kubectl top nodes                 # doit renvoyer des CPU/RAM chiffrés
  kubectl top pods -n sample-app
  ```

Variables utilisées ci-dessous :

```sh
NS=sample-app
RO=sample-app-sample-app   # <release>-<chart> => nom du Rollout ciblé par l'HPA
```

## Vue d'ensemble live (à garder ouvert pendant la démo)

**Terminal 1** — l'HPA et les replicas :

```sh
watch -n2 'kubectl get hpa,rollout -n '"$NS"
# colonne TARGETS : cpu: <actuel>%/50% ; colonne REPLICAS : monte de 2 -> 6
```

> Vue graphique optionnelle : le dashboard **Grafana** de soutenance
> (`k8s/monitoring/soutenance-dashboard.yaml`) montre CPU + replicas dans le temps.

## Étape 0 — état sain de départ

```sh
kubectl get hpa sample-app -n "$NS"        # TARGETS ~ cpu: 1%/50%, REPLICAS 2
kubectl top pods -n "$NS"                   # CPU faible au repos
```

Si la colonne TARGETS affiche `<unknown>/50%` → metrics-server n'est pas prêt
(voir *Dépannage* plus bas). **Ne pas démarrer la démo tant que ce n'est pas
`cpu: X%/50%`.**

## Étape 1 — envoyer la charge

Depuis ton poste, taper l'Ingress avec de la concurrence (installe `hey` :
`brew install hey` / `go install github.com/rakyll/hey@latest`) :

```sh
hey -z 3m -c 50 https://app.kubequest.epitech.beer/
# -z 3m : pendant 3 min ; -c 50 : 50 requêtes concurrentes
```

> Repli sans rien installer (moins agressif, monte moins le CPU) :
> ```sh
> kubectl run load -n "$NS" --image=busybox --restart=Never -- \
>   /bin/sh -c 'while true; do wget -q -O- http://sample-app-sample-app/ >/dev/null; done'
> ```
> Un seul `wget` séquentiel suffit rarement à dépasser 50 % — préférer `hey`.

## Étape 2 — observer le scale-up

Dans le Terminal 1, en ~15-60 s :

1. `TARGETS` dépasse `50%` (ex. `cpu: 180%/50%`).
2. `REPLICAS` de l'HPA passe `2 → 4 → 6` (plafond `maxReplicas`).
3. De nouveaux pods `sample-app` apparaissent et deviennent `Ready`.

Preuve des décisions de l'HPA :

```sh
kubectl describe hpa sample-app -n "$NS" | sed -n '/Events:/,$p'
# SuccessfulRescale ... New size: 4 ; reason: cpu resource utilization above target
```

## Étape 3 — couper la charge et commenter le cooldown

Arrêter `hey` (ou `kubectl delete pod load -n "$NS"`).

**Le nombre de replicas ne redescend PAS tout de suite** : c'est voulu. L'HPA
applique une fenêtre de stabilisation de **300 s (5 min)** au scale-down pour
éviter le *flapping* (monter/descendre en boucle sur des pics courts). Après ce
délai, `REPLICAS` revient à `2`.

> C'est un **point technique**, pas un bug : le dire au jury avant qu'il ne pose
> la question.

## La question piège du jury (et la réponse)

> « Votre GitOps (Argo CD selfHeal) ne remet-il pas `replicas: 2` du chart dès
> que l'HPA scale, entrant en conflit avec l'autoscaler ? »

Non : l'app `sample-app` déclare un `ignoreDifferences` sur `/spec/replicas` du
`Rollout` (voir `argocd/apps/sample-app/application.yaml`). Argo CD sort ce champ
de la réconciliation → l'HPA en est **seul propriétaire**, pas de bagarre.

## Dépannage

| Symptôme | Cause | Fix |
|---|---|---|
| HPA `TARGETS: <unknown>/50%` | metrics-server pas prêt | `kubectl top nodes` ; vérifier le pod `-n metrics-server` `Ready 1/1` |
| Pod metrics-server `Ready 0/1` | cert kubelet self-signé refusé | déjà géré par `--kubelet-insecure-tls` (voir `helm/metrics-server/values.yaml`) |
| Ne scale pas malgré la charge | charge trop faible | monter `-c` de `hey`, ou taper l'Ingress (pas le Service en cluster) |
| `TARGETS` calculé faux | request CPU manquante | le chart doit déclarer `resources.requests.cpu` (le % est calculé dessus) |

## Comment ça marche (rappel)

| Brique | Rôle |
|---|---|
| `metrics-server` | source de la Metrics API `metrics.k8s.io` (CPU/RAM réels) |
| `HorizontalPodAutoscaler` (`autoscaling/v2`) | compare CPU moyen à la cible 50 %, ajuste les replicas |
| `scaleTargetRef → Rollout` | Argo Rollouts implémente `/scale`, piloté comme un Deployment |
| `ignoreDifferences /spec/replicas` | empêche Argo CD selfHeal de se battre avec l'HPA |

Voir aussi [`ROLLBACK_DEMO.md`](./ROLLBACK_DEMO.md) et [`BEST_PRACTICES.md`](./BEST_PRACTICES.md).
