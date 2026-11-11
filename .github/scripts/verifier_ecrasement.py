#!/usr/bin/env python3
"""Vérifie, avant un déploiement, qu'aucun fichier du serveur ne sera écrasé sans accord.

Pour chaque fichier du dépôt, on compare trois versions :
  - D : la version du dépôt (ce qu'on veut envoyer) ;
  - S : la version actuellement sur le serveur (téléchargée juste avant) ;
  - E : l'empreinte de la dernière version déployée (fichier d'état FTP-Deploy-Action).

Règles :
  - fichier absent du serveur ...................... envoi (nouveau)
  - S identique à D ................................ rien à faire
  - S identique à E (non modifié depuis le dernier déploiement) ... écrasement autorisé
  - sinon (modifié sur le serveur, ou premier déploiement) ........ CONFLIT : demander

Les fins de ligne (CRLF / LF) sont ignorées dans la comparaison D / S.
Code de sortie 1 s'il y a des conflits et que l'écrasement n'a pas été autorisé.

Usage : verifier_ecrasement.py <dossier_depot_wp-content> <dossier_serveur> <fichier_etat|-> <oui|non>
"""
import hashlib
import json
import os
import subprocess
import sys
from datetime import datetime, timezone


def lire(chemin):
    with open(chemin, "rb") as f:
        return f.read()


def sha256(octets):
    return hashlib.sha256(octets).hexdigest()


def normaliser(octets):
    return octets.replace(b"\r\n", b"\n")


def fichiers_du_depot(racine):
    sortie = subprocess.run(
        ["git", "ls-files", "-z", "--", "."], cwd=racine, check=True, capture_output=True
    ).stdout.decode("utf-8")
    return sorted(p for p in sortie.split("\0") if p)


def fichiers_du_serveur(racine):
    resultat = set()
    for dossier, _, noms in os.walk(racine):
        for nom in noms:
            chemin = os.path.relpath(os.path.join(dossier, nom), racine).replace(os.sep, "/")
            if chemin != ".ftp-deploy-sync-state.json":
                resultat.add(chemin)
    return resultat


def date_serveur(chemin):
    return datetime.fromtimestamp(os.path.getmtime(chemin), tz=timezone.utc).strftime("%Y-%m-%d %H:%M UTC")


def main():
    depot, serveur, fichier_etat, ecraser = sys.argv[1:5]
    ecraser = ecraser.strip().lower() == "oui"

    etat = {}
    if fichier_etat != "-" and os.path.isfile(fichier_etat):
        try:
            for entree in json.loads(lire(fichier_etat)).get("data", []):
                if entree.get("type") == "file":
                    etat[entree["name"]] = entree.get("hash")
        except (ValueError, KeyError):
            print("::warning::Fichier d'état illisible : tout fichier différent sera traité comme un conflit.")
    premier = not etat

    nouveaux, identiques, mis_a_jour, conflits = [], [], [], []
    for nom in fichiers_du_depot(depot):
        s_chemin = os.path.join(serveur, nom)
        if not os.path.isfile(s_chemin):
            nouveaux.append(nom)
            continue
        d, s = lire(os.path.join(depot, nom)), lire(s_chemin)
        if normaliser(d) == normaliser(s):
            identiques.append(nom)
        elif etat.get(nom) == sha256(s):
            mis_a_jour.append(nom)
        else:
            conflits.append((nom, date_serveur(s_chemin)))

    seulement_serveur = sorted(fichiers_du_serveur(serveur) - set(fichiers_du_depot(depot)))

    lignes = ["## Vérification avant envoi sur la prod", ""]
    if premier:
        lignes += ["_Premier déploiement : aucune version déployée connue, toute différence est un conflit._", ""]
    lignes += [
        f"- Nouveaux fichiers (absents du serveur) : **{len(nouveaux)}**",
        f"- Identiques (rien à envoyer) : **{len(identiques)}**",
        f"- Mis à jour (serveur non modifié depuis le dernier déploiement) : **{len(mis_a_jour)}**",
        f"- **Conflits (modifiés sur le serveur) : {len(conflits)}**",
        f"- Présents seulement sur le serveur (jamais touchés) : {len(seulement_serveur)}",
        "",
    ]
    if conflits:
        lignes += ["### Conflits", "", "| Fichier | Date sur le serveur |", "|---|---|"]
        lignes += [f"| `{n}` | {d} |" for n, d in conflits]
        lignes += [""]
        if ecraser:
            lignes += ["**Écrasement autorisé manuellement (ecraser = oui) : ces fichiers vont être remplacés.**"]
        else:
            lignes += [
                "**Déploiement arrêté : rien n'a été envoyé.**",
                "",
                "Pour décider :",
                "- garder la version du serveur : la récupérer dans le dépôt (commit), puis relancer ;",
                "- écraser avec la version du dépôt : onglet Actions > « Déploiement prod » > Run workflow, ecraser = **oui**.",
            ]
    rapport = "\n".join(lignes) + "\n"
    print(rapport)
    resume = os.environ.get("GITHUB_STEP_SUMMARY")
    if resume:
        with open(resume, "a", encoding="utf-8") as f:
            f.write(rapport)

    if conflits and not ecraser:
        print(f"::error::{len(conflits)} fichier(s) modifié(s) sur le serveur : accord requis avant écrasement.")
        sys.exit(1)


if __name__ == "__main__":
    main()
