import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { PocApp } from "../src/PocApp";

vi.mock("../src/api/pocApi", () => ({
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

function renderPocApp() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false }
    }
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <PocApp />
    </QueryClientProvider>
  );
}

describe("PocApp", () => {
  it("renders the operational overview shell", async () => {
    renderPocApp();

    expect(await screen.findByRole("heading", { name: "Overview operativa" })).toBeInTheDocument();
    expect(await screen.findByText("Contenuti generati")).toBeInTheDocument();
  });
});
