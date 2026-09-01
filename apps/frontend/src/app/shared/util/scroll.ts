/**
 * Unico punto della codebase che tocca il DOM per lo scroll: i ViewModel non chiamano mai `document`/`window` direttamente.
 * Con `prefers-reduced-motion: reduce` il salto e' immediato invece che animato.
 */
export function scrollToElement(elementId: string): void {
  window.requestAnimationFrame(() => {
    document.getElementById(elementId)?.scrollIntoView({
      behavior: prefersReducedMotion() ? "auto" : "smooth",
      block: "start"
    });
  });
}

function prefersReducedMotion(): boolean {
  return window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
}
