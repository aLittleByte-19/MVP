import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { Alert } from "./Alert";

describe("Alert", () => {
  it("renders an accessible alert with title and message", () => {
    render(<Alert title="Errore">Servizio non disponibile.</Alert>);

    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "Errore" })).toBeInTheDocument();
    expect(screen.getByText("Servizio non disponibile.")).toBeInTheDocument();
  });
});
