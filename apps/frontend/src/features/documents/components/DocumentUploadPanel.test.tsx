import { fireEvent, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { renderWithQueryClient } from "../../../test/render";
import { DocumentUploadPanel } from "./DocumentUploadPanel";

describe("DocumentUploadPanel", () => {
  it("submits the selected PDF file", () => {
    const onUpload = vi.fn();
    const file = new File(["%PDF"], "cedolino.pdf", { type: "application/pdf" });

    renderWithQueryClient(
      <DocumentUploadPanel isUploading={false} onUpload={onUpload} status="Nessun caricamento in corso." />
    );

    fireEvent.change(screen.getByLabelText("Seleziona o trascina un documento"), { target: { files: [file] } });

    expect(onUpload).toHaveBeenCalledWith(file);
    expect(screen.getByText("Nessun caricamento in corso.")).toBeInTheDocument();
  });
});
