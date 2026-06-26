import { getDocumentStatus, getReviewStatusTone } from "./status";

describe("status utilities", () => {
  it("marks errored documents as warning and clean documents as success", () => {
    expect(getDocumentStatus("OCR incompleto")).toBe("warning");
    expect(getDocumentStatus(null)).toBe("success");
  });

  it("maps review states to visual tones used by badges", () => {
    expect(getReviewStatusTone("quarantined")).toBe("danger");
    expect(getReviewStatusTone("needs_review")).toBe("warning");
    expect(getReviewStatusTone("auto_validated")).toBe("info");
    expect(getReviewStatusTone("manually_validated")).toBe("success");
    expect(getReviewStatusTone("unknown")).toBe("neutral");
  });
});
