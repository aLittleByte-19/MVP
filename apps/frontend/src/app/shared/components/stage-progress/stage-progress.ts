import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";

/** Una tappa della pipeline, con la sua etichetta leggibile. */
export interface ProgressStage {
  readonly id: string;
  readonly label: string;
}

/** Stato di una tappa rispetto a quella corrente. */
type StageState = "done" | "current" | "pending" | "failed";

/**
 * Avanzamento per tappe di una pipeline asincrona.
 *
 * Sostituisce la barra a percentuali: quelle erano una tabella fissa
 * fase → numero (20, 40, 60, 85, 100) che non corrispondeva ad alcuna misura —
 * un documento "al 60%" non era a sei decimi di nulla. Le tappe invece sono gli
 * stati reali che arrivano dallo stream SSE, quindi la stessa informazione
 * viene mostrata senza inventare una quantità.
 *
 * `slow` non è una tappa ma un'annotazione su quella corrente: la pipeline sta
 * ancora nello stesso stato, solo da più tempo del previsto.
 */
@Component({
  selector: "mvp-stage-progress",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ol class="stages" [attr.aria-label]="label()">
      @for (stage of decorated(); track stage.id) {
        <li [class]="stage.state" [attr.aria-current]="stage.state === 'current' ? 'step' : null">
          <span class="mark">
            <span class="glyph" aria-hidden="true">{{ stage.glyph }}</span>
            <span class="state">{{ stage.stateLabel }}</span>
          </span>
          <span class="name">{{ stage.label }}</span>
        </li>
      }
    </ol>
    @if (slow() && !failed()) {
      <p class="slow" role="status">
        L'elaborazione sta impiegando più del previsto. La lavorazione continua in background.
      </p>
    }
  `,
  styleUrl: "./stage-progress.css"
})
export class StageProgressComponent {
  readonly stages = input.required<readonly ProgressStage[]>();
  /** Tappa raggiunta; `null` finché la pipeline non è partita. */
  readonly currentId = input<string | null>(null);
  readonly failed = input(false);
  readonly slow = input(false);
  readonly label = input("Avanzamento elaborazione");

  protected readonly decorated = computed(() => {
    const stages = this.stages();
    const currentIndex = stages.findIndex((stage) => stage.id === this.currentId());
    const failed = this.failed();

    return stages.map((stage, index) => {
      const state = this.stateFor(index, currentIndex, failed);

      return {
        id: stage.id,
        label: stage.label,
        state,
        glyph: GLYPHS[state],
        stateLabel: STATE_LABELS[state]
      };
    });
  });

  private stateFor(index: number, currentIndex: number, failed: boolean): StageState {
    if (currentIndex === -1) {
      return "pending";
    }

    if (index < currentIndex) {
      return "done";
    }

    if (index > currentIndex) {
      return "pending";
    }

    // Il fallimento colora la tappa in cui si è verificato, non tutte: le
    // precedenti erano riuscite davvero.
    return failed ? "failed" : "current";
  }
}

/** Una forma oltre al colore, per non affidare lo stato al solo contrasto. */
const GLYPHS: Record<StageState, string> = {
  done: "✓",
  current: "◐",
  pending: "○",
  failed: "×"
};

const STATE_LABELS: Record<StageState, string> = {
  done: "Completato",
  current: "In corso",
  pending: "In attesa",
  failed: "Non riuscito"
};
