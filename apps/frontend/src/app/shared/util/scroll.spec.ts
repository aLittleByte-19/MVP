import { scrollToElement } from "./scroll";

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

    scrollToElement("target-section");

    expect(target.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "start" });
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
