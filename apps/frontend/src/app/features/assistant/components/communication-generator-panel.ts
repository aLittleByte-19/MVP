import { ChangeDetectionStrategy, Component, effect, input, output, signal } from "@angular/core";
import { GenerationProgressComponent } from "./generation-progress";
import type { CommunicationGenerationPhase } from "../assistant.model";
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
  GenerateCommunicationRequestTone,
  PromptConfiguration,
  SavePromptConfigurationRequest
} from "../../../../api/generated/model";

@Component({
  selector: "mvp-communication-generator-panel",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    GenerationProgressComponent,
    LucideSend,
    ReactiveFormsModule,
    SectionComponent,
    SelectFieldComponent,
    TextAreaFieldComponent
  ],
  template: `
    <mvp-section class="form" id="assistant-compose" title="Crea una bozza">
      <p class="note">
        Il prompt descrive cosa comunicare; tono e stile guidano la resa. La bozza generata resta modificabile
        nell'anteprima.
      </p>
      <form [formGroup]="form" (ngSubmit)="submit()">
        <mvp-textarea-field
          label="Prompt"
          [rows]="6"
          placeholder="Oggetto della comunicazione interna"
          [control]="form.controls.prompt"
        />
        <div class="fieldRow">
          <mvp-select-field label="Tono" [options]="tones" [control]="form.controls.tone" />
          <mvp-select-field label="Stile" [options]="styles" [control]="form.controls.style" />
        </div>
        <button mvpButton type="submit" [disabled]="isGenerating()" [busy]="isGenerating()">
          <svg lucideSend aria-hidden="true"></svg>
          <span>Genera bozza</span>
        </button>
      </form>
      @if (phase() !== null && phase() !== "idle") {
        <mvp-generation-progress [phase]="phase()" />
      }
      <p class="status" aria-live="polite">{{ status() }}</p>

      <div class="configActions">
        @if (isConfiguringName()) {
          <label class="field configName">
            <span>Nome configurazione (opzionale)</span>
            <input
              type="text"
              [formControl]="configNameControl"
              placeholder="Nome automatico se lasciato vuoto"
              maxlength="150"
            />
          </label>
          @if (saveConfigurationError()) {
            <p class="warning" role="alert">{{ saveConfigurationError() }}</p>
          }
          <button
            mvpButton
            type="button"
            [disabled]="isSavingConfiguration() || form.controls.prompt.invalid"
            (click)="confirmSaveConfiguration()"
          >
            Conferma salvataggio
          </button>
          <button
            mvpButton
            variant="secondary"
            type="button"
            [disabled]="isSavingConfiguration()"
            (click)="isConfiguringName.set(false)"
          >
            Annulla
          </button>
        } @else {
          <button
            mvpButton
            variant="secondary"
            type="button"
            [disabled]="form.controls.prompt.invalid"
            (click)="isConfiguringName.set(true)"
          >
            Salva configurazione
          </button>
        }
      </div>
    </mvp-section>
  `,
  styleUrls: [
    "../../../shared/styles/field.css",
    "../../../shared/styles/notice.css",
    "./communication-generator-panel.css"
  ]
})
export class CommunicationGeneratorPanelComponent {
  readonly isGenerating = input.required<boolean>();
  readonly status = input.required<string>();
  readonly phase = input.required<CommunicationGenerationPhase | "idle" | null>();
  readonly promptConfigurations = input<PromptConfiguration[]>([]);
  readonly isSavingConfiguration = input<boolean>(false);
  readonly saveConfigurationError = input<string | null>(null);
  /** Valori da caricare nel form da fuori (riuso di una configurazione salvata, UC-19). */
  readonly prefill = input<CommunicationDraftForm | null>(null);
  readonly generate = output<CommunicationDraftForm>();
  readonly saveConfiguration = output<SavePromptConfigurationRequest>();

  protected readonly tones = communicationTones;
  protected readonly styles = communicationStyles;
  protected readonly isConfiguringName = signal(false);
  protected readonly configNameControl = new FormControl("", { nonNullable: true });

  /** Distingue un salvataggio riuscito (elenco allungato) da un nuovo riferimento a parita' di conteggio. */
  private lastPromptConfigurationCount: number | null = null;
  protected readonly form = new FormGroup({
    prompt: new FormControl(
      "Scrivi una comunicazione interna per informare i dipendenti che la nuova area documentale NEXUM è disponibile. Spiega cosa cambia, dove trovare i documenti e perché la consultazione diventa più semplice.",
      { nonNullable: true, validators: [Validators.required, Validators.minLength(12)] }
    ),
    tone: new FormControl<GenerateCommunicationRequestTone>("Chiaro e diretto", { nonNullable: true }),
    style: new FormControl<GenerateCommunicationRequestStyle>("Testo informativo", { nonNullable: true })
  });

  constructor() {
    // Riuso di una configurazione salvata (UC-19): il genitore spinge i valori da fuori.
    effect(() => {
      const values = this.prefill();

      if (values) {
        this.form.setValue(values);
      }
    });

    // Chiude il modulo nome solo se l'elenco si e' allungato (salvataggio riuscito, UC-19);
    // un errore o una mutazione altrove nella pagina lo lascia aperto.
    effect(() => {
      const count = this.promptConfigurations().length;
      const grew = this.lastPromptConfigurationCount !== null && count > this.lastPromptConfigurationCount;
      this.lastPromptConfigurationCount = count;

      if (!grew) {
        return;
      }

      this.isConfiguringName.set(false);
      this.configNameControl.reset("");
    });
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    // Una nuova generazione chiude un salvataggio configurazione lasciato a meta'.
    this.isConfiguringName.set(false);
    this.configNameControl.reset("");
    this.generate.emit(this.form.getRawValue());
  }

  protected confirmSaveConfiguration(): void {
    if (this.form.controls.prompt.invalid) {
      this.form.controls.prompt.markAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    const name = this.configNameControl.value.trim();

    this.saveConfiguration.emit({
      name: name === "" ? undefined : name,
      prompt: raw.prompt,
      tone: raw.tone,
      style: raw.style
    });
  }

}
