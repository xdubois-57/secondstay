# CLAUDE.md

Lire et appliquer `AGENTS.md` **avant toute modification**.

Les sources de vérité persistantes du projet sont, dans cet ordre :

1. `AGENTS.md` — règles obligatoires de contribution par agent.
2. `SPECIFICATIONS.md` — exigences produit et métier.
3. `ARCHITECTURE.md` — architecture et responsabilités.
4. `SECURITY.md` — exigences de sécurité.
5. `I18N.md` — exigences FR/EN/NL/DE.
6. `TESTING.md` — Definition of Done et stratégie de tests.
7. `RELEASE.md` — règles de CI/release.
8. `ROADMAP.md` — ordre des itérations.
9. `README.md` — vue d’ensemble.

Si le code et la documentation divergent, ne pas choisir silencieusement l’un des deux :

- préserver la sécurité et les données ;
- relire les exigences concernées ;
- corriger code **et** documentation dans la même modification.

Une tâche est incomplète si elle rend l’un de ces fichiers matériellement faux.

Commande de validation locale de référence :

```bash
./scripts/check.sh
```

Ne jamais considérer un changement terminé si les tests pertinents ne passent pas.

## Fin de tâche : fusionner sur `main`

Consigne du propriétaire du dépôt : **un travail terminé est toujours fusionné
sur `main` sur GitHub**, pour que rien ne reste sur une branche de travail.

1. pousser la branche de travail ;
2. ouvrir une pull request vers `main` ;
3. attendre que les contrôles obligatoires soient verts — dont
   `Claude review status`, qui prouve qu'une revue Claude a réellement eu
   lieu — ne jamais fusionner au rouge, ni contourner une protection de
   branche ;
4. fusionner la pull request.

Si la fusion est impossible (contrôle rouge, conflit, protection), le dire
explicitement plutôt que de laisser la branche en suspens.

Une pull request qui modifie `.github/workflows/claude-review.yml` ne peut pas
obtenir de revue Claude (l'action refuse de relire un workflow différent de
`main`) : ne jamais la fusionner soi-même, la laisser au mainteneur, qui seul
peut décider d'une dérogation (`docs/quality-pipeline.md`).
