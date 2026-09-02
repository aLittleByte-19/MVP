import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from "@angular/core";

/** Una tappa della pipeline, con la sua etichetta leggibile. */
export interface ProgressStage {
  readonly id: string;
  readonly label: string;
}

/** Stato di una tappa rispetto a quella corrente. */
type StageState = "done" | "current" | "pending" | "failed";

/**
 * Avanzamento per tappe reali (dallo stream SSE), non una percentuale inventata senza misura corrispondente.
 * `slow` non e' una tappa ma un'annotazione sulla corrente. Il contatore del tempo trascorso e' `aria-hidden`: un valore che cambia ogni secondo in una regione live coprirebbe ogni altro annuncio.
 */
@Component({
  selector: "mvp-stage-progress",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <ol class="stages" [attr.aria-label]="label()">
      @for (stage of decorated(); track stage.id) {
        <li [class]="stage.state" [attr.aria-current]="stage.state === 'current' ? 'step' : null">
          <span class="track">
            <span class="marker" aria-hidden="true">{{ stage.glyph }}</span>
          </span>
          <span class="body">
            <span class="name">{{ stage.label }}</span>
            <span class="state">{{ stage.stateLabel }}</span>
          </span>
        </li>
      }
    </ol>
    @if (elapsedLabel(); as elapsed) {
      <p class="elapsed" aria-hidden="true">{{ failed() ? "Interrotta dopo" : "In corso da" }} {{ elapsed }}</p>
    }
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

  protected readonly elapsedSeconds = signal<number | null>(null);

  protected readonly elapsedLabel = computed(() => {
    const seconds = this.elapsedSeconds();
    if (seconds === null) {
      return null;
    }

    if (seconds < 60) {
      return `${seconds} s`;
    }

    const minutes = Math.floor(seconds / 60);
    return `${minutes} min ${String(seconds % 60).padStart(2, "0")} s`;
  });

  /** Istante di partenza della corsa in corso. */
  private startedAt: number | null = null;

  /** Il solo `currentId` non basta per accorgersi di una nuova corsa: fra una generazione e la successiva non torna a `null`. */
  private lastIndex = -1;

  private wasFinished = false;

  constructor() {
    effect((onCleanup) => {
      const currentIndex = this.stages().findIndex((stage) => stage.id === this.currentId());
      const failed = this.failed();
      const finished = failed || currentIndex === this.stages().length - 1;

      if (currentIndex === -1) {
        this.startedAt = null;
        this.lastIndex = -1;
        this.wasFinished = false;
        this.elapsedSeconds.set(null);
        return;
      }

      // Si riparte quando l'avanzamento torna indietro, o quando riprende dopo
      // essersi fermato: in entrambi i casi quella che si misura e' una corsa
      // nuova, e il tempo riparte da zero.
      if (currentIndex < this.lastIndex || (this.wasFinished && ! finished)) {
        this.startedAt = null;
      }

      this.startedAt ??= Date.now();
      this.lastIndex = currentIndex;
      this.wasFinished = finished;

      const tick = () => this.elapsedSeconds.set(Math.floor((Date.now() - (this.startedAt ?? 0)) / 1000));
      tick();

      // A corsa conclusa il numero resta fermo sull'ultimo valore: dice quanto
      // è durata, che è un'informazione, mentre continuare a contare sarebbe
      // una misura di nulla.
      if (finished) {
        return;
      }

      const timer = setInterval(tick, 1000);
      onCleanup(() => clearInterval(timer));
    });
  }

  protected readonly decorated = computed(() => {
    const stages = this.stages();
    const currentIndex = stages.findIndex((stage) => stage.id === this.currentId());
    const failed = this.failed();

    return stages.map((stage, index) => {
      const state = this.stateFor(index, currentIndex, failed, stages.length - 1);

      return {
        id: stage.id,
        label: stage.label,
        state,
        glyph: GLYPHS[state],
        stateLabel: STATE_LABELS[state]
      };
    });
  });

  private stateFor(index: number, currentIndex: number, failed: boolean, lastIndex: number): StageState {
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
    if (failed) {
      return "failed";
    }

    // Raggiungere l'ultima tappa vuol dire che la corsa è finita, non che ne
    // è cominciato un altro passo: restando "in corso" l'avanzamento si
    // fermava per sempre a un passo dalla fine, con l'ultima tappa che
    // annunciava "Completato — In corso".
    return index === lastIndex ? "done" : "current";
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
