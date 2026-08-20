import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { type DensityBucket, densityShape } from "../../util/charts";

const WIDTH = 260;
const HEIGHT = 74;

/**
 * Scheda della densita' delle durate: quante elaborazioni per fascia di tempo,
 * come curva continua.
 *
 * Risponde a "com'e' fatto il lavoro" — dove si concentra e quanto e' lunga la
 * coda — che e' una domanda diversa da "quanto dura in media", a cui risponde
 * la scheda delle fasi. Per questo non ripete alcun numero grande: il valore
 * medio sta gia' nella scheda accanto, e qui conta la forma.
 *
 * L'asse verticale non ha tacche numeriche: e' una densita' stimata da qualche
 * decina di misure, e numerarla darebbe una precisione che il dato non ha.
 * Quello orizzontale invece e' fitto, perche' e' li' che si legge la durata.
 *
 * Sotto una manciata di campioni la curva sarebbe disegnata
 * dall'interpolazione piu' che dai dati: al suo posto compare una riga di
 * testo che dice quante misure ci sono.
 */
@Component({
  selector: "mvp-metric-distribution",
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="card" [attr.aria-busy]="isLoading() ? 'true' : null">
      <h3 class="label">{{ label() }}</h3>
      @if (isLoading()) {
        <span class="skeleton chart" aria-hidden="true"></span>
        <span class="srOnly">Caricamento in corso</span>
      } @else if (shape(); as density) {
        <svg
          class="chart"
          [attr.viewBox]="'0 0 ' + width + ' ' + height"
          preserveAspectRatio="none"
          role="img"
          [attr.aria-label]="summary()"
        >
          @for (tick of density.ticks; track tick.label) {
            <line class="grid" [attr.x1]="tick.x" y1="0" [attr.x2]="tick.x" [attr.y2]="height" />
          }
          <path class="area" [attr.d]="density.area" />
          <path class="line" [attr.d]="density.line" />
        </svg>
        <p class="axis" aria-hidden="true">
          @for (tick of density.ticks; track tick.label) {
            <span>{{ tick.label }}{{ $last ? unit() : "" }}</span>
          }
        </p>
      } @else {
        <p class="few">{{ fallback() }}</p>
      }
    </section>
  `,
  styleUrls: ["../../styles/metric-shell.css", "./metric-distribution.css"]
})
export class MetricDistributionComponent {
  readonly label = input.required<string>();
  readonly buckets = input<readonly DensityBucket[] | undefined>(undefined);
  /** Quante misure stanno dietro la curva: sotto la soglia non si disegna. */
  readonly sampleSize = input(0);
  readonly minSamples = input(8);
  readonly unit = input("s");
  /** Come si chiamano le corse misurate: elaborazioni, generazioni. */
  readonly subject = input("elaborazioni");
  readonly isLoading = input(false);

  protected readonly width = WIDTH;
  protected readonly height = HEIGHT;

  protected readonly shape = computed(() => {
    const buckets = this.buckets();

    if (buckets === undefined || this.sampleSize() < this.minSamples()) {
      return null;
    }

    return densityShape(buckets, WIDTH, HEIGHT);
  });

  protected readonly fallback = computed(() => {
    const samples = this.sampleSize();
    const plural = this.subject();
    // Le due grandezze misurate sono "elaborazioni" e "generazioni": la stessa
    // desinenza, quindi il singolare si ricava senza tabelle.
    const singular = plural.replace(/ioni$/, "ione");

    if (samples === 0) {
      return `Nessuna ${singular} conclusa negli ultimi sette giorni.`;
    }

    return samples === 1
      ? `Una sola ${singular} conclusa: troppo poche per un andamento.`
      : `Solo ${samples} ${plural} concluse: troppo poche per un andamento.`;
  });

  /** Descrizione per chi non vede la curva: dove sta la massa e dove finisce. */
  protected readonly summary = computed(() => {
    const buckets = this.buckets() ?? [];

    if (buckets.length === 0) {
      return "";
    }

    const busiest = buckets.reduce((top, bucket) => (bucket.count > top.count ? bucket : top), buckets[0]!);
    const longest = buckets[buckets.length - 1]!;

    return (
      `Durate: la fascia piu' popolata arriva a ${busiest.upTo}${this.unit()} ` +
      `con ${busiest.count} ${this.subject()}; la piu' lenta entro ${longest.upTo}${this.unit()}.`
    );
  });
}
