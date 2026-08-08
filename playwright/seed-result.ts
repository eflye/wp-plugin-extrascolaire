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
