# 0005 - Gestion des secrets avec HashiCorp Vault (standalone + Raft)

- Statut : Accepted
- Date : 2026-07-02
- Décideurs : équipe KubeQuest
- PR : à compléter à l'ouverture

## Contexte

La gestion des secrets Kubernetes du cluster est aujourd'hui 100% manuelle et
hors-Git : `mysql-credentials` (`k8s/mysql/README.md`),
`dex-github`/`dex-clients` (`k8s/dex/README.md`), `grafana-admin`/
`grafana-oidc` (`k8s/monitoring/README.md`) et `oauth2-proxy`
(`k8s/oauth2-proxy/README.md`) sont tous créés à la main via
`kubectl create secret generic ... --from-literal=$(openssl rand -base64 24)`.
Cette dette technique est déjà documentée à plusieurs endroits — le README
MySQL évoque même explicitement Sealed Secrets comme piste jamais
implémentée.

## Décision

Déployer **HashiCorp Vault** en mode **standalone avec Integrated Storage
(Raft)** : un seul pod, storage backend Raft plutôt que le backend `file`
historique, sans mode HA multi-replica du chart. Activer le **Vault Agent
Injector** packagé nativement dans le chart officiel
(`injector.enabled`, défaut du chart). Configurer l'auth method Kubernetes
avec une policy/role minimale de démonstration (`demo-policy`/`demo-role`,
KV `secret/demo/example`) pour prouver que l'ensemble fonctionne, **sans**
migrer les secrets existants dans ce lot — ce sera un travail futur séparé.

Exposer l'UI via Ingress + cert-manager (`letsencrypt-prod`) + **oauth2-proxy**
en forward-auth (`k8s/oauth2-proxy/README.md`), car l'UI Vault OSS n'a pas
d'OIDC natif simple à activer, contrairement à Grafana.

## Alternatives envisagées

- **Vault HA (3+ replicas Raft)** : rejeté. Le cluster ne dispose que de 2
  workers à 4 GB RAM chacun, déjà partagés entre MySQL, le stack monitoring,
  Argo CD et le reste — pas de marge confortable pour un quorum Raft
  multi-nœud en plus.
- **Auto-unseal AWS KMS dès ce lot** : rejeté pour l'instant. Nécessite du
  wiring IAM (rôle IAM pour le pod, policy KMS) absent de l'infra Terraform
  actuelle. Reporté en dette technique explicite (voir Conséquences).
- **Sealed Secrets** (déjà évoqué comme piste dans `k8s/mysql/README.md`) à la
  place de Vault : rejeté pour ce lot — l'énoncé demande spécifiquement
  HashiCorp Vault, qui apporte en plus l'injection dynamique via l'Injector
  (pas seulement du chiffrement statique de manifestes Secret).
- **External Secrets Operator + Vault** au lieu de l'Injector natif : non
  retenu pour ce lot. L'Injector est packagé nativement dans le chart
  officiel (`injector.enabled`), plus simple à poser en fondation ; ESO
  pourra être réévalué lors de la migration effective des secrets existants.

## Conséquences

### Positives

- Fondation posée pour sortir de la gestion manuelle des secrets : Injector
  fonctionnel, auth Kubernetes activée, démontré avec un exemple KV de bout
  en bout.
- Reste dans le pattern "vendored manifest" du repo : aucun `helm install`
  ni release Helm sur le cluster, tout passe par un manifeste rendu et
  committé, synchronisé par Argo CD.

### Négatives / points d'attention

- **Pas d'auto-unseal** : chaque redémarrage du pod (OOMKill, reschedule,
  reboot du nœud) re-scelle Vault et exige une intervention manuelle avec 3
  des 5 clés — tant que cette intervention n'a pas eu lieu, Vault et tout
  consommateur futur de l'Injector sont indisponibles.
- **Pas de HA** : un seul pod, donc un point de défaillance unique assumé.
- **Secrets existants non migrés** : Vault coexiste avec les Secrets créés à
  la main jusqu'à un futur ticket de migration.
- **Stockage `local-path`** : comme MySQL, le PVC est lié au disque local
  d'un seul nœud worker — pas de résilience en cas de perte du nœud.
