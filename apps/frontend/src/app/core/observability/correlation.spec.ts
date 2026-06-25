import {
  CORRELATION_ID_HEADER,
  REQUEST_ID_HEADER,
  generateRequestId
} from "./correlation";

describe("frontend correlation", () => {
  it("uses backend-compatible header names", () => {
    expect(REQUEST_ID_HEADER).toBe("X-Request-ID");
    expect(CORRELATION_ID_HEADER).toBe("X-Correlation-ID");
  });

  it("generates opaque request identifiers", () => {
    const first = generateRequestId();
    const second = generateRequestId();

    expect(first).toEqual(expect.any(String));
    expect(first.length).toBeGreaterThan(8);
    expect(second).not.toBe(first);
  });
});
