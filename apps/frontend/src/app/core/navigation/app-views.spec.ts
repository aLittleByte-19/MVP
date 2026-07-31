import { isMvpView, mvpNavGroups, mvpViewTitles } from "./app-views";

describe("app views", () => {
  it.each(["overview", "assistant", "copilot"])("riconosce la vista %s", (view) => {
    expect(isMvpView(view)).toBe(true);
  });

  it.each([undefined, null, "settings", 1])("rifiuta una vista non supportata", (value) => {
    expect(isMvpView(value)).toBe(false);
  });

  it("mantiene titolo e navigazione definiti per ogni vista", () => {
    const views = mvpNavGroups.flatMap((group) => group.items.map((item) => item.id));

    expect(views).toEqual(["overview", "assistant", "copilot"]);
    expect(views.every((view) => Boolean(mvpViewTitles[view]))).toBe(true);
  });
});
