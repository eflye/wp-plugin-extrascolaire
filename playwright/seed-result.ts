/**
 * Lecture du résultat de seed écrit par global-setup.ts
 * (.playwright/seed.<profile>.json) — la façon dont une spec doit
 * consommer open_day/locked_day/mois/e-mail parent/index enfants plutôt
 * que de reparser la sortie WP-CLI elle-même.
 */

import { readFileSync } from 'node:fs';
import * as path from 'node:path';
import type { SeedResult } from './global-setup';

export type { SeedResult };

/**
 * URL de la page portant le formulaire.
 *
 * Propriété du site et non du profil : les deux profils la rapportent
 * identique, on lit donc le premier résultat de peuplement disponible. Cela
 * permet aux fonctions utilitaires d'une spec (connexion famille, par
 * exemple) de l'obtenir sans connaître le profil courant.
 *
 * Les scénarios visaient auparavant un `?page_id=` codé en dur, relevé sur
 * l'installation de développement : sur un site fraîchement installé la page
 * en porte un autre, et la page ouverte n'était pas la bonne.
 */
export function readFormPageUrl(): string {
  for (const profile of ['test', 'demo'] as const) {
    try {
      return readSeedResult(profile).form_page_url;
    } catch {
      // profil non peuplé pour cette exécution : on essaie l'autre
    }
  }
  throw new Error(
    'Aucun résultat de peuplement lisible : global-setup ne s\'est exécuté pour aucun profil.'
  );
}

export function readSeedResult(profile: 'test' | 'demo'): SeedResult {
  const file = path.resolve(__dirname, '..', '.playwright', `seed.${profile}.json`);
  let raw: string;
  try {
    raw = readFileSync(file, 'utf8');
  } catch {
    throw new Error(
      `${file} introuvable : global-setup ne s'est pas exécuté pour le profil '${profile}' ` +
        `(le projet Playwright correspondant a-t-il bien été sélectionné avec --project=${profile} ?).`
    );
  }
  return JSON.parse(raw) as SeedResult;
}
