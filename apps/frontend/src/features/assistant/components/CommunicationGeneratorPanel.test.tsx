import { fireEvent, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { renderWithQueryClient } from "../../../test/render";
import { CommunicationGeneratorPanel } from "./CommunicationGeneratorPanel";

describe("CommunicationGeneratorPanel", () => {
  it("submits prompt, tone and style", async () => {
    const onGenerate = vi.fn();

    renderWithQueryClient(
      <CommunicationGeneratorPanel
        isGenerating={false}
        onGenerate={onGenerate}
        status="In attesa di istruzioni."
      />
    );

    fireEvent.change(screen.getByLabelText("Prompt"), {
      target: { value: "Genera una comunicazione interna completa." }
    });
    fireEvent.change(screen.getByLabelText("Tono"), { target: { value: "Tecnico" } });
    fireEvent.change(screen.getByLabelText("Stile"), { target: { value: "Avviso operativo" } });
    fireEvent.click(screen.getByRole("button", { name: "Genera bozza" }));

    await waitFor(() => {
      expect(onGenerate).toHaveBeenCalledWith(
        {
          prompt: "Genera una comunicazione interna completa.",
          tone: "Tecnico",
          style: "Avviso operativo"
        },
        expect.anything()
      );
    });
  });
});
