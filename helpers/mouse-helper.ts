/**
 * Curseur visible en profil demo — adaptation du "mouse-helper" classique
 * (exemples Puppeteer / communauté Playwright). Chromium piloté par CDP ne
 * rend aucun curseur OS réel : sans cet artefact, la vidéo ne montrerait
 * aucun retour visuel sur les clics.
 *
 * Les actions Playwright (page.click, page.fill, ...) déplacent la souris
 * via le domaine CDP Input, qui déclenche de vrais événements DOM
 * mousemove/mousedown/mouseup — ce helper n'a donc besoin d'écouter que
 * ces événements standard, aucune API Playwright spécifique.
 *
 * Injecté via context.addInitScript() : réévalué automatiquement dans
 * chaque nouveau document du contexte, y compris après une navigation.
 */

import type { BrowserContext } from '@playwright/test';

function initScript() {
  const ID = 'psc-mouse-helper';
  const HALO_ID = 'psc-mouse-helper-halo';
  if ((document as any)[ID]) return;
  (document as any)[ID] = true;

  // 24px, lisible au vidéoprojecteur (l'ancien point à 20px se perdait à
  // l'écran une fois projeté). Le halo est un anneau séparé qui s'étend et
  // s'estompe à chaque clic — retour visuel clair même sur un clic bref.
  const CSS = `
    #${ID} {
      pointer-events: none;
      position: fixed;
      top: 0; left: 0;
      width: 24px; height: 24px;
      margin: -12px 0 0 -12px;
      background: rgba(0, 128, 255, 0.55);
      border: 3px solid rgba(0, 90, 200, 0.95);
      border-radius: 50%;
      z-index: 2147483647;
      transition: transform 120ms ease, background 120ms ease;
      transform: scale(1);
    }
    #${ID}.psc-down { transform: scale(0.8); background: rgba(255, 90, 0, 0.75); }

    #${HALO_ID} {
      pointer-events: none;
      position: fixed;
      top: 0; left: 0;
      width: 24px; height: 24px;
      margin: -12px 0 0 -12px;
      border-radius: 50%;
      border: 3px solid rgba(255, 120, 0, 0.9);
      z-index: 2147483646;
      opacity: 0;
      transform: scale(1);
    }
    #${HALO_ID}.psc-pulse {
      animation: psc-mouse-halo-pulse 550ms ease-out;
    }
    @keyframes psc-mouse-halo-pulse {
      0%   { opacity: 0.9; transform: scale(1); }
      100% { opacity: 0;   transform: scale(2.6); }
    }
  `;

  function mount() {
    const root = document.body || document.documentElement;
    if (!root) {
      requestAnimationFrame(mount);
      return;
    }
    const style = document.createElement('style');
    style.textContent = CSS;
    (document.head || document.documentElement).appendChild(style);

    const box = document.createElement('div');
    box.id = ID;
    root.appendChild(box);

    const halo = document.createElement('div');
    halo.id = HALO_ID;
    root.appendChild(halo);

    document.addEventListener(
      'mousemove',
      (event) => {
        box.style.left = `${event.clientX}px`;
        box.style.top = `${event.clientY}px`;
      },
      true
    );
    document.addEventListener(
      'mousedown',
      (event) => {
        box.classList.add('psc-down');
        halo.style.left = `${event.clientX}px`;
        halo.style.top = `${event.clientY}px`;
        // Relance l'animation même sur des clics rapprochés : retirer la
        // classe, forcer un reflow, la remettre.
        halo.classList.remove('psc-pulse');
        void halo.offsetWidth;
        halo.classList.add('psc-pulse');
      },
      true
    );
    document.addEventListener('mouseup', () => box.classList.remove('psc-down'), true);
  }

  mount();
}

/** À appeler une fois par test, avant toute navigation (profil demo uniquement). */
export async function installMouseHelper(context: BrowserContext): Promise<void> {
  await context.addInitScript(initScript);
}
