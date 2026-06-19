# 0003 - Build multi-arch natif de l'image sample-app

- Statut : Accepted
- Date : 2026-06-19
- Décideurs : équipe KubeQuest
- PR : [#71](https://github.com/T-CLO-902-KubeQuest/KubeQuest/pull/71)

## Contexte

Le cluster tourne sur des nœuds **arm64** (AWS Graviton), mais le workflow
`sample-app-build.yml` ne buildait l'image que pour l'architecture du runner
GitHub (amd64). Les pods restaient en `ImagePullBackOff` avec l'erreur
`no match for platform in manifest` : l'image publiée n'avait pas de variante
arm64.

Un premier correctif (#70) a ajouté **QEMU** pour émuler arm64 sur le runner
amd64. Fonctionnel, mais l'émulation est lente et fragile (composer / build PHP
sous QEMU). On a cherché une approche native.

GitHub fournit désormais des **runners ARM64 hébergés** (`ubuntu-24.04-arm`,
Graviton), **gratuits sur les dépôts publics** — ce qui est notre cas. On peut
donc builder chaque architecture sur son propre runner natif, sans émulation.

## Décision

Refondre le workflow en **multi-arch natif par matrice**, en trois jobs :

1. **`prepare`** — calcule une seule fois la version semver (dry-run) et le nom
   d'image (lowercased), exposés en `outputs` pour que les deux builds publient
   sous la même version.
2. **`build`** (matrice) — un job natif par architecture :
   - `linux/amd64` sur `ubuntu-24.04`,
   - `linux/arm64` sur `ubuntu-24.04-arm`.
   Chaque job build l'image, la scanne avec **Trivy** (le scan reste bloquant et
   couvre donc chaque architecture), puis la pousse **par digest**
   (`push-by-digest=true`, sans tag). Le cache GHA est **scopé par arch**
   (`scope=${arch}`) pour éviter qu'une arch écrase le cache de l'autre.
3. **`publish`** — récupère les digests des deux builds et assemble le
   **manifest list** multi-arch via `docker buildx imagetools create`, en y
   apposant les tags semver / `latest` / `sha`. Puis tag git et release du chart
   Helm (version = appVersion = même semver que l'image).

## Alternatives envisagées

- **QEMU (approche #70)** : retenue temporairement, puis remplacée. Émulation
  lente et moins fiable pour le build PHP/composer ; un seul runner.
- **Runner self-hosted sur les nœuds Graviton du cluster** : build arm64 natif
  mais infrastructure à déployer et maintenir (sécurité, mises à jour, capacité).
  Rejeté tant que les runners ARM hébergés gratuits couvrent le besoin.
- **arm64 seul (pas de multi-arch)** : suffirait pour le cluster actuel, mais on
  perd la portabilité amd64 (dev local, futur nœud x86) pour un gain marginal.
  Le surcoût d'un second job natif parallèle est faible.

## Conséquences

### Positives

- Image **portable** `linux/amd64` + `linux/arm64` ; tourne sur le cluster
  Graviton et en local x86.
- Builds **natifs et parallèles** : plus rapides et plus fiables que QEMU ; le
  temps total du pipeline est celui du job le plus lent, pas la somme.
- Scan Trivy bloquant conservé, désormais par architecture.

### Négatives / points d'attention

- Dépend de la disponibilité des runners hébergés `ubuntu-24.04-arm` et de la
  **gratuité sur dépôt public** : passer le dépôt en privé facturerait ces
  minutes ARM, ou imposerait un runner self-hosted.
- Pipeline plus complexe (3 jobs, artefacts de digests) qu'un job linéaire.
- Au premier run d'une arch, son cache GHA est froid → ce build est plus long ;
  l'écart se résorbe aux runs suivants (cache chaud).
