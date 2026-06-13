import { Send } from "lucide-react";
import { useForm } from "react-hook-form";
import { Button } from "../../../components/inputs/Button";
import { SelectField } from "../../../components/inputs/SelectField";
import { TextAreaField } from "../../../components/inputs/TextAreaField";
import { Section } from "../../../components/layout/Section";
import { communicationStyles, communicationTones, type CommunicationDraftForm } from "../types";
import styles from "./CommunicationGeneratorPanel.module.css";

type CommunicationGeneratorPanelProps = {
  isGenerating: boolean;
  onGenerate: (payload: CommunicationDraftForm) => void;
  status: string;
};

export function CommunicationGeneratorPanel({ isGenerating, onGenerate, status }: CommunicationGeneratorPanelProps) {
  const form = useForm<CommunicationDraftForm>({
    defaultValues: {
      prompt:
        "Scrivi una comunicazione interna per informare i dipendenti che la nuova area documentale NEXUM e disponibile. Spiega cosa cambia, dove trovare i documenti e perche la consultazione diventa piu semplice.",
      tone: "Chiaro e diretto",
      style: "Testo informativo"
    }
  });

  return (
    <Section className={styles.form} id="assistant-compose" title="Crea una bozza">
      <p className={styles.note}>
        Inserisci cosa comunicare e pochi parametri editoriali. La bozza resta modificabile nella revisione.
      </p>
      <form onSubmit={form.handleSubmit(onGenerate)}>
        <TextAreaField
          label="Prompt"
          rows={6}
          placeholder="Descrivi la comunicazione interna da generare."
          {...form.register("prompt", { required: true, minLength: 12 })}
        />
        <div className={styles.fieldRow}>
          <SelectField label="Tono" options={communicationTones} {...form.register("tone")} />
          <SelectField label="Stile" options={communicationStyles} {...form.register("style")} />
        </div>
        <Button type="submit" disabled={isGenerating}>
          <Send aria-hidden="true" size={18} />
          <span>{isGenerating ? "Generazione" : "Genera bozza"}</span>
        </Button>
      </form>
      <p className={styles.status} aria-live="polite">
        {status}
      </p>
    </Section>
  );
}
