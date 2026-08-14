/**
 * Scorre fino all'elemento con l'id indicato, se presente, dopo il
 * prossimo frame. Unico punto della codebase che tocca il DOM per lo
 * scroll: i ViewModel (Presentation Model) non chiamano mai `document`/
 * `window` direttamente — ricevono questa funzione come dipendenza dal
 * Component (la View), che resta l'unico responsabile della resa visiva.
 */
export function scrollToElement(elementId: string): void {
  window.requestAnimationFrame(() => {
    document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
  });
}
