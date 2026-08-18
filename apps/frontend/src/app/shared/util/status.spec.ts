import { getReviewStatusTone, getSendStatusTone } from "./status";

describe("status utilities", () => {
  it("maps review states to visual tones used by badges", () => {
    expect(getReviewStatusTone("quarantined")).toBe("danger");
    expect(getReviewStatusTone("needs_review")).toBe("warning");
    expect(getReviewStatusTone("auto_validated")).toBe("info");
    expect(getReviewStatusTone("manually_validated")).toBe("success");
    expect(getReviewStatusTone("unknown")).toBe("neutral");
  });

  it("maps send status to visual tones used by badges", () => {
    expect(getSendStatusTone("sent")).toBe("success");
    expect(getSendStatusTone("pending")).toBe("warning");
  });
});
