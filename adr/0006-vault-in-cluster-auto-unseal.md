# 0006 - Auto-unseal in-cluster de Vault avec vault-unsealer

- Statut : Accepted
- Date : 2026-07-02
- Décideurs : équipe KubeQuest
- PR : à compléter à l'ouverture

## Contexte

L'ADR [0005](0005-vault-secrets-management.md) a déployé Vault standalone en
assumant l'absence d'auto-unseal comme dette technique : chaque redémarrage du
pod re-scelle Vault et exige une intervention manuelle avec 3 des 5 clés
Shamir. Or ce cluster est **éteint toutes les nuits** : sans automatisation,
Vault redémarrerait scellé chaque matin, rendant indisponible tout futur
consommateur de l'Injector jusqu'à une intervention humaine quotidienne —
inacceptable même pour un cluster de démonstration.

L'évolution "production" identifiée (auto-unseal `seal "awskms"`) reste
bloquée par l'absence de wiring IAM dans l'infra Terraform.

## Décision

Déployer **[bakito/vault-unsealer](https://github.com/bakito/vault-unsealer)**
(chart Helm 0.4.1, vendored manifest `k8s/vault/unsealer.yaml`, même
Application ArgoCD que Vault) : un controller Kubernetes qui surveille, dans
le namespace `vault`, les Secrets portant le label
`vault-unsealer.bakito.net/stateful-set=vault` et déverrouille automatiquement
les pods du StatefulSet avec les entrées `unsealKey*` du Secret.

Le Secret `vault-unseal-keys` (3 des 5 clés, le seuil Shamir) est créé à la
main hors Git, comme les autres secrets du cluster (`mysql-credentials`,
`dex-clients`…). Il doit **persister** : le cluster étant éteint chaque nuit,
un cache mémoire seul serait perdu à chaque arrêt.

Critères de choix du projet : activité récente (v0.4.1, janvier 2026), Go,
chart Helm vendorable selon le pattern du repo, RBAC scoped au namespace, et
securityContext par défaut déjà conforme aux ClusterPolicies Kyverno
enforced (drop ALL, runAsNonRoot, no privilege escalation, seccomp
RuntimeDefault).

## Alternatives envisagées

- **Auto-unseal AWS KMS** (`seal "awskms"`) : toujours la cible production,
  mais nécessite un rôle IAM pour le pod et une policy KMS absents de l'infra
  Terraform actuelle. Reporté, inchangé depuis l'ADR 0005.
- **Unseal manuel quotidien** (statu quo de l'ADR 0005) : rejeté — le cluster
  éteint chaque nuit transforme la dette "intervention après incident" en
  corvée quotidienne garantie.
- **pyToshka/vault-autounseal** : gère aussi l'init automatique, mais projet
  Python peu actif (dernière release août 2024) ; l'init automatique n'a
  aucun intérêt ici (opération unique déjà faite).
- **bank-vaults (vault-operator)** : operator complet qui remplacerait le
  déploiement par chart officiel de l'ADR 0005 — disproportionné pour le
  besoin.

## Conséquences

### Positives

- Vault se déverrouille seul au démarrage matinal du cluster et après tout
  redémarrage de pod (OOMKill, reschedule) : plus d'intervention quotidienne.
- Reste dans le pattern vendored manifest + GitOps du repo ; le controller
  est conforme aux policies Kyverno sans exception.

### Négatives / points d'attention

- **Le seal ne protège plus contre un attaquant in-cluster** : quiconque peut
  lire les Secrets du namespace `vault` peut déverrouiller Vault. Le modèle
  de menace couvert se réduit au vol du disque/volume seul (les données Raft
  restent chiffrées au repos). Compromis assumé pour ce cluster de démo ;
  l'auto-unseal KMS rétablirait une frontière de confiance externe.
- Les clés d'unseal existent désormais à deux endroits : le gestionnaire de
  mots de passe de l'équipe (les 5) et le Secret in-cluster (3 sur 5).
- Dépendance à un projet communautaire de faible notoriété (9 étoiles) —
  atténuée par la simplicité du fallback : `vault operator unseal` manuel
  reste documenté dans `k8s/vault/README.md`.
