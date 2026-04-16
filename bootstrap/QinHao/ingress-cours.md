# Ingress Kubernetes — Cours rapide

---

## L'analogie : le videur de boite de nuit

Imagine une boite de nuit avec plusieurs salles (tes applications/pods).

| Concept K8s | Analogie |
|---|---|
| **Pod** | Une salle dans la boite (ou la musique joue) |
| **Service** | Le couloir qui mene a une salle |
| **Ingress Controller** | Le videur a l'entree de la boite |
| **Ingress** | La liste que tu donnes au videur : "si la personne dit qu'elle va au `hello-world.local`, envoie-la vers le couloir X" |

Sans videur (Ingress Controller), tu peux quand meme entrer dans les salles, mais par des portes de service numerotees au hasard (c'est le `NodePort`, genre port `32492`). Pas tres pratique.

---

## Avant l'Ingress : ce qu'on avait deja

```
Internet/Navigateur
       |
  http://192.168.49.2:32492    <-- port aleatoire, moche
       |
  Service (NodePort)
       |
  Pod (echo-server)
```

Ca marchait, mais il fallait connaitre le numero de port.

---

## Avec l'Ingress : ce qu'on a maintenant

```
Internet/Navigateur
       |
  http://hello-world.local     <-- nom de domaine, port 80, propre
       |
  Ingress Controller (NGINX)   <-- le "videur", ecoute sur le port 80
       |
  Ingress (la regle)           <-- "hello-world.local -> hello-world-service"
       |
  Service
       |
  Pod (echo-server)
```

---

## Ce qu'on a fait, etape par etape

### Etape 1 -- Installer le videur (Ingress Controller)

```bash
minikube addons enable ingress
```

Ca installe un serveur NGINX dans ton cluster qui ecoute le trafic entrant sur le port 80.

### Etape 2 -- Donner les regles au videur (Ingress YAML)

```yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: hello-world-ingress
spec:
  rules:
  - host: hello-world.local          # si le visiteur demande ce domaine...
    http:
      paths:
      - path: /                       # ...et ce chemin...
        pathType: Prefix
        backend:
          service:
            name: hello-world-service # ...envoie-le vers ce Service
            port:
              number: 80
```

En francais : "Quand quelqu'un arrive en demandant `hello-world.local/`, redirige-le vers `hello-world-service` sur le port 80."

### Etape 3 -- Faire pointer le nom de domaine vers Minikube

```
# dans /etc/hosts
192.168.49.2  hello-world.local
```

Comme `hello-world.local` n'est pas un vrai domaine sur Internet, on dit a notre machine : "quand tu cherches `hello-world.local`, va a l'IP `192.168.49.2` (= Minikube)".

---

## Pourquoi c'est utile ?

Avec l'Ingress, tu peux router **plusieurs applications** sur la meme IP, juste avec des noms differents :

```yaml
rules:
- host: app1.local     # -> service-app1
- host: app2.local     # -> service-app2
- host: api.local      # -> service-api
```

Un seul point d'entree (port 80), plusieurs destinations. C'est exactement ce que fait un reverse-proxy comme NGINX, sauf que Kubernetes le gere pour toi automatiquement.

---

## Resume

1. **Ingress Controller** = le reverse-proxy (NGINX) qui tourne dans le cluster
2. **Ingress** = la ressource YAML qui definit les regles de routage (quel domaine -> quel service)
3. **/etc/hosts** = en local, on associe manuellement le domaine a l'IP de Minikube
4. En production, un vrai DNS remplacerait `/etc/hosts`

---

*KubeQuest -- Cours Ingress*
