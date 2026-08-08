/**
 * Habillage vidéo du profil "demo" : spotlight sur un testid, sous-titre,
 * carton plein cadre. À n'installer que sur le contexte du projet demo —
 * ce module ne vérifie rien lui-même, l'appelant décide (cf. commentaire
 * dans playwright.config.ts).
 *
 * Le script est injecté via `context.addInitScript()`, donc au niveau du
 * BrowserContext plutôt que d'une Page précise : Playwright le réévalue
 * automatiquement dans CHAQUE document créé dans ce contexte, y compris
 * après une navigation ou une redirection (cf. étape 05 du parcours, qui
 * enchaîne un clic puis une redirection serveur — vérifié manuellement en
 * conditions réelles avant livraison : le spotlight/sous-titre posés avant
 * le clic restent fonctionnels sur la page redirigée sans réinstallation
 * explicite).
 */

import type { BrowserContext, Page } from '@playwright/test';

const NS = '__pscDemo';

/**
 * Exécuté dans la page. addInitScript sérialise cette fonction via
 * toString() : aucune fermeture sur une variable externe au module ne
 * survit (NS doit donc être reçu en paramètre, jamais lu depuis la
 * constante ci-dessus — piège vérifié en conditions réelles : une
 * première version qui lisait `NS` par fermeture échouait silencieusement
 * avec un ReferenceError côté page, `window.__pscDemo` ne se posait
 * jamais).
 */
function initScript(ns: string) {
  const STYLE_ID = 'psc-demo-overlay-style';
  const SPOT_ID = 'psc-demo-spotlight';
  const SUB_ID = 'psc-demo-subtitle';
  const CARD_ID = 'psc-demo-carton';

  function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
      #${SPOT_ID} {
        position: fixed;
        left: 0; top: 0; width: 0; height: 0;
        z-index: 2147483000;
        pointer-events: none;
        border-radius: 8px;
        opacity: 0;
        /* Le "trou" de lumière est un box-shadow inversé à spread énorme
           depuis un rectangle positionné sur la cible — jamais un
           transform:scale sur <html>, qui provoquerait un reflow visible
           et casserait la mise en page pendant l'enregistrement. */
        box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.72);
        transition: left 300ms ease, top 300ms ease, width 300ms ease,
                    height 300ms ease, opacity 300ms ease;
      }
      #${SPOT_ID}.psc-visible { opacity: 1; }
      #${SPOT_ID}.psc-fullscreen {
        left: 0; top: 0; width: 100vw; height: 100vh;
        box-shadow: none;
        background: rgba(0, 0, 0, 0.82);
      }
      #${SUB_ID} {
        position: fixed; left: 0; right: 0; bottom: 0;
        z-index: 2147483001;
        box-sizing: border-box;
        min-height: 64px;
        padding: 16px 40px 34px;
        background: rgba(0, 0, 0, 0.88);
        color: #fff;
        font: 600 30px/1.35 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        text-align: center;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.7);
        opacity: 0;
        pointer-events: none;
        transition: opacity 200ms ease;
      }
      #${SUB_ID}.psc-visible { opacity: 1; }
      #${CARD_ID} {
        position: fixed; inset: 0;
        z-index: 2147483002;
        display: flex; align-items: center; justify-content: center;
        background: #0b1220;
        color: #fff;
        font: 700 44px/1.4 -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        text-align: center;
        padding: 10vh 12vw;
        box-sizing: border-box;
        opacity: 0;
        pointer-events: none;
        transition: opacity 300ms ease;
      }
      #${CARD_ID}.psc-visible { opacity: 1; }
    `;
    document.documentElement.appendChild(style);
  }

  function ensureNodes() {
    ensureStyle();
    const root = document.body || document.documentElement;

    let spot = document.getElementById(SPOT_ID);
    if (!spot) {
      spot = document.createElement('div');
      spot.id = SPOT_ID;
      root.appendChild(spot);
    }
    let sub = document.getElementById(SUB_ID);
    if (!sub) {
      sub = document.createElement('div');
      sub.id = SUB_ID;
      root.appendChild(sub);
    }
    let card = document.getElementById(CARD_ID);
    if (!card) {
      card = document.createElement('div');
      card.id = CARD_ID;
      root.appendChild(card);
    }
    return { spot, sub, card };
  }

  let subtitleTimer: ReturnType<typeof setTimeout> | null = null;
  let cartonTimer: ReturnType<typeof setTimeout> | null = null;

  (window as any)[ns] = {
    spotlight(testid: string | null) {
      const { spot } = ensureNodes();

      if (testid === null) {
        spot.classList.remove('psc-visible', 'psc-fullscreen');
        return;
      }

      if (testid === 'overlay' || testid === 'carton') {
        spot.classList.add('psc-fullscreen', 'psc-visible');
        return;
      }

      spot.classList.remove('psc-fullscreen');

      const el = document.querySelector(`[data-testid="${CSS.escape(testid)}"]`);
      if (!el) {
        spot.classList.remove('psc-visible');
        return;
      }

      const pad = 8;
      const r = el.getBoundingClientRect();
      spot.style.left = `${Math.max(0, r.left - pad)}px`;
      spot.style.top = `${Math.max(0, r.top - pad)}px`;
      spot.style.width = `${r.width + pad * 2}px`;
      spot.style.height = `${r.height + pad * 2}px`;
      spot.classList.add('psc-visible');
    },

    subtitle(text: string, durationMs: number) {
      const { sub } = ensureNodes();
      if (subtitleTimer) clearTimeout(subtitleTimer);

      if (!text) {
        sub.classList.remove('psc-visible');
        sub.textContent = '';
        return;
      }

      sub.textContent = text;
      sub.classList.add('psc-visible');
      subtitleTimer = setTimeout(() => {
        sub.classList.remove('psc-visible');
        sub.textContent = '';
        subtitleTimer = null;
      }, durationMs);
    },

    carton(text: string, durationMs: number) {
      const { card } = ensureNodes();
      if (cartonTimer) clearTimeout(cartonTimer);

      card.textContent = text;
      card.classList.add('psc-visible');
      cartonTimer = setTimeout(() => {
        card.classList.remove('psc-visible');
        cartonTimer = null;
      }, durationMs);
    },
  };
}

/** À appeler une fois par test, avant toute navigation (profil demo uniquement). */
export async function installDemoOverlay(context: BrowserContext): Promise<void> {
  await context.addInitScript(initScript, NS);
}

/** null retire le spotlight ; "overlay"/"carton" assombrissent l'écran entier sans découpe. */
export async function spotlight(page: Page, testidOrNull: string | null): Promise<void> {
  await page.evaluate(
    ([ns, testid]) => (window as any)[ns as string]?.spotlight(testid),
    [NS, testidOrNull] as const
  );
}

/**
 * Affiche le sous-titre puis attend durationMs avant de rendre la main —
 * le texte se vide de lui-même côté navigateur à l'issue du même délai
 * (cf. initScript), l'attente ici sert à caler le rythme de la prise sur
 * la durée du storyboard (journeys/parent-connu.md).
 */
export async function subtitle(page: Page, text: string, durationMs: number): Promise<void> {
  await page.evaluate(
    ([ns, text, durationMs]) => (window as any)[ns as string]?.subtitle(text, durationMs),
    [NS, text, durationMs] as const
  );
  await page.waitForTimeout(durationMs);
}

/** Écran plein assombri, texte centré — étapes 00-carton-titre / 12-carton-fin. */
export async function carton(page: Page, text: string, durationMs: number): Promise<void> {
  await page.evaluate(
    ([ns, text, durationMs]) => (window as any)[ns as string]?.carton(text, durationMs),
    [NS, text, durationMs] as const
  );
  await page.waitForTimeout(durationMs);
}
