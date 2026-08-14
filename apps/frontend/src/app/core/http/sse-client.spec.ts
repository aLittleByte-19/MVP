import { consumeSseBuffer, resolveSseUrl } from "./sse-client";

describe("SseClient helpers", () => {
  it("lascia invariato un URL assoluto e antepone apiBaseUrl a uno relativo", () => {
    expect(resolveSseUrl("https://example.test/stream")).toBe("https://example.test/stream");
    expect(resolveSseUrl("/api/v1/documents/1/stream")).toBe("/api/v1/documents/1/stream");
  });

  it("estrae event e data ignorando i commenti keepalive", () => {
    const frames: { event: string; data: string }[] = [];
    const rest = consumeSseBuffer(
      ": keepalive\n\nevent: progress\ndata: {\"status\":\"processing\"}\n\nevent: still_running\ndata: {\"message\":\"ancora in corso\"}\n\nparziale",
      (event, data) => frames.push({ event, data })
    );

    expect(frames).toEqual([
      { event: "progress", data: "{\"status\":\"processing\"}" },
      { event: "still_running", data: "{\"message\":\"ancora in corso\"}" }
    ]);
    expect(rest).toBe("parziale");
  });
});
