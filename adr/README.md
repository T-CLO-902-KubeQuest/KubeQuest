# Architecture Decision Records

Ce dossier regroupe les **ADR** (Architecture Decision Records) du projet KubeQuest :
des notes courtes qui documentent une décision d'architecture significative, son
contexte et ses conséquences.

## Convention

- Un fichier par décision, nommé `NNNN-titre-en-kebab-case.md` (numérotation
  séquentielle à quatre chiffres).
- Format inspiré de [MADR](https://adr.github.io/madr/).
- Une fois `Accepted`, un ADR n'est pas réécrit : s'il devient obsolète, on le
  passe en `Superseded` et on rédige un nouvel ADR qui le remplace.

## Index

| ADR | Titre | Statut |
| --- | --- | --- |
| [0001](0001-migrate-sample-app-to-laravel-12.md) | Migration de la sample-app vers Laravel 12 | Accepted |
| [0002](0002-mysql-network-policy-and-backup-hardening.md) | Durcissement de la NetworkPolicy et des backups MySQL | Accepted |
