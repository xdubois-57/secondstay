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
