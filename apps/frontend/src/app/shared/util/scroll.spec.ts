import { scrollToElement } from "./scroll";

/**
 * jsdom non implementa matchMedia: va definita prima di poterla sostituire.
 * Restituisce il ripristino, per non lasciare la finestra alterata.
 */
function mockReducedMotion(reduce: boolean): () => void {
  const original = window.matchMedia;

  Object.defineProperty(window, "matchMedia", {
    configurable: true,
    writable: true,
    value: (query: string) => ({ matches: reduce, media: query }) as MediaQueryList
  });

  return () => Object.defineProperty(window, "matchMedia", { configurable: true, writable: true, value: original });
}

describe("scrollToElement", () => {
  it("scorre fino all'elemento se presente nel DOM", () => {
    const target = document.createElement("div");
    target.id = "target-section";
    target.scrollIntoView = jest.fn();
    document.body.appendChild(target);
    const animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });
    const restoreMedia = mockReducedMotion(false);

    scrollToElement("target-section");

    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "start" });
    restoreMedia();
    animation.mockRestore();
    target.remove();
  });

  it("salta senza animazione per chi ha chiesto meno movimento", () => {
    const target = document.createElement("div");
    target.id = "target-ridotto";
    target.scrollIntoView = jest.fn();
    document.body.appendChild(target);
    const animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });
    const restoreMedia = mockReducedMotion(true);

    scrollToElement("target-ridotto");

    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: "auto", block: "start" });
    restoreMedia();
    animation.mockRestore();
    target.remove();
  });

  it("non solleva errori se l'elemento non esiste", () => {
    const animation = jest.spyOn(window, "requestAnimationFrame").mockImplementation((callback) => {
      callback(0);
      return 1;
    });

    expect(() => scrollToElement("non-esistente")).not.toThrow();

    animation.mockRestore();
  });
});
