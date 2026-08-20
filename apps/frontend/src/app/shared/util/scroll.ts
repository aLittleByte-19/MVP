/**
 * Scorre fino all'elemento con l'id indicato, se presente, dopo il
 * prossimo frame. Unico punto della codebase che tocca il DOM per lo
 * scroll: i ViewModel (Presentation Model) non chiamano mai `document`/
 * `window` direttamente — ricevono questa funzione come dipendenza dal
 * Component (la View), che resta l'unico responsabile della resa visiva.
 *
 * Lo scorrimento e' morbido solo per chi non ha chiesto meno animazioni: con
 * `prefers-reduced-motion: reduce` il salto e' immediato, perche' uno
 * spostamento lungo e animato e' fra i movimenti che quella preferenza esiste
 * per evitare.
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
