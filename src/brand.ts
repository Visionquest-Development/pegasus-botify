/**
 * Brand matching (build spec §6.3).
 *
 * When "inherit theme" is on, read the Pegasus theme's CSS custom properties from :root and
 * use them for the widget's accent colors. Otherwise (or when a property is absent) fall
 * back to the plugin's own appearance settings, then to sane defaults.
 */

import type { RenderConfig } from './config';

export interface BrandColors {
  primary: string;
  secondary: string;
}

function readVar(name: string): string | null {
  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  return value || null;
}

export function resolveBrandColors(cfg: RenderConfig): BrandColors {
  const a = cfg.appearance;

  let primary = a.accentColor || a.bubbleColor;
  let secondary = a.headerColor;

  if (a.inheritTheme) {
    primary = primary || readVar('--pegasus-header-bkg-color') || readVar('--pegasus-nav-item-color');
    secondary = secondary || readVar('--pegasus-footer-bkg-color') || readVar('--pegasus-header-bkg-color');
  }

  return {
    primary: primary || '#2563eb',
    secondary: secondary || '#1e293b',
  };
}
