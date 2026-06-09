import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { renderWithQueryClient } from "../test/render";
import { App } from "./App";

vi.mock("../api/pocApi", () => ({
  getPocState: vi.fn(async () => ({
    assistant: {
      metrics: [
        { value: 1, label: "Contenuti generati" },
        { value: 1, label: "Bozze generate" }
      ],
      history: []
    },
    copilot: {
      metrics: [
        { value: 0, label: "Documenti analizzati" },
        { value: 0, label: "Sotto-documenti rilevati" }
      ],
      documents: []
    }
  })),
  generatePocCommunication: vi.fn(),
  uploadPocDocument: vi.fn(),
  deletePocSubDocument: vi.fn()
}));

describe("App", () => {
  it("renders the operational overview shell", async () => {
    renderWithQueryClient(<App />);

    expect(await screen.findByRole("heading", { name: "Overview operativa" })).toBeInTheDocument();
    expect(await screen.findByText("Contenuti generati")).toBeInTheDocument();
  });
});
