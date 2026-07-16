import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from "@angular/forms";
import { LucideSend } from "@lucide/angular";
import { ButtonComponent } from "../../../shared/components/button/button";
import { SelectFieldComponent } from "../../../shared/components/select-field/select-field";
import { TextAreaFieldComponent } from "../../../shared/components/textarea-field/textarea-field";
import { SectionComponent } from "../../../layout/section/section";
import {
  communicationStyles,
  communicationTones,
  type CommunicationDraftForm
} from "../assistant.model";
import type {
  GenerateCommunicationRequestStyle,
  GenerateCommunicationRequestTone
} from "../../../../api/generated/model";

@Component({
  selector: "mvp-communication-generator-panel",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    LucideSend,
    ReactiveFormsModule,
    SectionComponent,
    SelectFieldComponent,
    TextAreaFieldComponent
  ],
  template: `
    <mvp-section class="form" id="assistant-compose" title="Crea una bozza">
      <p class="note">
        Inserisci cosa comunicare e mvphi parametri editoriali. Genera la bozza e rivedi titolo e testo nell'anteprima.
      </p>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <mvp-textarea-field
          label="Prompt"
          [rows]="6"
          placeholder="Descrivi la comunicazione interna da generare."
          [control]="form.controls.prompt"
        />
        <div class="fieldRow">
          <mvp-select-field label="Tono" [options]="tones" [control]="form.controls.tone" />
          <mvp-select-field label="Stile" [options]="styles" [control]="form.controls.style" />
        </div>
        <button mvpButton type="submit" [disabled]="isGenerating()">
          <svg lucideSend aria-hidden="true"></svg>
          <span>{{ isGenerating() ? "Generazione" : "Genera bozza" }}</span>
        </button>
      </form>
      <p class="status" aria-live="polite">{{ status() }}</p>
    </mvp-section>
  `,
  styleUrl: "./communication-generator-panel.css"
})
export class CommunicationGeneratorPanelComponent {
  readonly isGenerating = input.required<boolean>();
  readonly status = input.required<string>();
  readonly generate = output<CommunicationDraftForm>();

  protected readonly tones = communicationTones;
  protected readonly styles = communicationStyles;
  protected readonly form = new FormGroup({
    prompt: new FormControl(
      "Scrivi una comunicazione interna per informare i dipendenti che la nuova area documentale NEXUM e disponibile. Spiega cosa cambia, dove trovare i documenti e perche la consultazione diventa piu semplice.",
      { nonNullable: true, validators: [Validators.required, Validators.minLength(12)] }
    ),
    tone: new FormControl<GenerateCommunicationRequestTone>("Chiaro e diretto", { nonNullable: true }),
    style: new FormControl<GenerateCommunicationRequestStyle>("Testo informativo", { nonNullable: true })
  });

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.generate.emit(this.form.getRawValue());
  }
}
