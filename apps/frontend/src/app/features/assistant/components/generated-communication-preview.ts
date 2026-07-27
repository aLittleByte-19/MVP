import { ChangeDetectionStrategy, Component, computed, effect, input, output, signal } from "@angular/core";
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from "@angular/forms";
import { LucidePencil, LucideSave, LucideX } from "@lucide/angular";
import { EmptyStateComponent } from "../../../shared/components/empty-state/empty-state";
import { StatusBadgeComponent } from "../../../shared/components/status-badge/status-badge";
import { ButtonComponent } from "../../../shared/components/button/button";
import { SectionComponent } from "../../../layout/section/section";
import type { UpdateCommunicationRequest } from "../../../../api/generated/model";
import type { GeneratedDraft } from "../assistant.model";

@Component({
  selector: "mvp-generated-communication-preview",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    ButtonComponent,
    EmptyStateComponent,
    LucidePencil,
    LucideSave,
    LucideX,
    ReactiveFormsModule,
    SectionComponent,
    StatusBadgeComponent
  ],
  template: `
    <mvp-section id="assistant-review" title="Controlla il contenuto">
      <span actions class="meta">{{ bodyLength() }} caratteri</span>
      @if (draft(); as currentDraft) {
        <article class="draft">
          <div class="cover">
            <span>Bozza generata</span>
          </div>
          @if (saveError()) {
            <p class="errorNote">{{ saveError() }}</p>
          }
          <form [formGroup]="form" [class.isEditing]="isEditing()" (ngSubmit)="save(currentDraft.id)">
            <label class="field">
              <span>Titolo</span>
              <input [readOnly]="!isEditing()" [attr.aria-readonly]="!isEditing()" formControlName="title" />
            </label>
            <label class="field">
              <span>Testo</span>
              <textarea
                rows="7"
                [readOnly]="!isEditing()"
                [attr.aria-readonly]="!isEditing()"
                formControlName="body"
              ></textarea>
            </label>
            <div class="footer">
              <mvp-status-badge>{{ currentDraft.status }}</mvp-status-badge>
              <span>Creato da AI Assistant{{ isEditing() ? "" : " · anteprima in sola lettura" }}</span>
            </div>
            <div class="reviewActions">
              @if (isEditing()) {
                <button mvpButton variant="secondary" type="button" [disabled]="isSaving()" (click)="cancelEdit(currentDraft)">
                  <svg lucideX aria-hidden="true"></svg>
                  Annulla
                </button>
                <button mvpButton type="submit" [disabled]="isSaving() || form.invalid">
                  <svg lucideSave aria-hidden="true"></svg>
                  {{ isSaving() ? "Salvataggio" : "Salva" }}
                </button>
              } @else if (currentDraft.statusValue === "draft") {
                <button mvpButton variant="secondary" type="button" (click)="startEdit(currentDraft)">
                  <svg lucidePencil aria-hidden="true"></svg>
                  Modifica
                </button>
              }
            </div>
          </form>
        </article>
      } @else {
        <mvp-empty-state>La bozza generata apparira qui.</mvp-empty-state>
      }
    </mvp-section>
  `,
  styleUrl: "./generated-communication-preview.css"
})
export class GeneratedCommunicationPreviewComponent {
  readonly draft = input<GeneratedDraft | null>(null);
  readonly isSaving = input<boolean>(false);
  readonly saveError = input<string | null>(null);
  readonly saveRequested = output<{ communicationId: number; payload: UpdateCommunicationRequest }>();

  protected readonly bodyLength = computed(() => this.draft()?.body.length ?? 0);
  protected readonly isEditing = signal(false);
  protected readonly form = new FormGroup({
    title: new FormControl("", { nonNullable: true, validators: [Validators.required, Validators.maxLength(255)] }),
    body: new FormControl("", { nonNullable: true, validators: [Validators.required, Validators.maxLength(20000)] })
  });

  constructor() {
    effect(() => {
      const currentDraft = this.draft();
      this.form.setValue({ title: currentDraft?.title ?? "", body: currentDraft?.body ?? "" });
      this.isEditing.set(false);
    });
  }

  protected startEdit(currentDraft: GeneratedDraft): void {
    this.form.setValue({ title: currentDraft.title, body: currentDraft.body });
    this.isEditing.set(true);
  }

  protected cancelEdit(currentDraft: GeneratedDraft): void {
    this.form.setValue({ title: currentDraft.title, body: currentDraft.body });
    this.isEditing.set(false);
  }

  protected save(communicationId: number): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.saveRequested.emit({ communicationId, payload: this.form.getRawValue() });
  }
}
