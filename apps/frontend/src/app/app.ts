import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  signal
} from "@angular/core";
import { NavigationEnd, Router, RouterOutlet } from "@angular/router";
import { takeUntilDestroyed } from "@angular/core/rxjs-interop";
import { auditTime, filter, fromEvent } from "rxjs";
import { LucideArrowUp, LucideMoon, LucideSun } from "@lucide/angular";
import { ButtonComponent } from "./shared/components/button/button";
import {
  isMvpView,
  mvpNavGroups,
  mvpViewTitles,
  type MvpView
} from "./core/navigation/app-views";
import { MvpStateStore } from "./core/state/mvp-state.store";
import { ThemeService } from "./core/theme/theme.service";

/** Quota di viewport oltre cui l'inizio di una sezione la rende quella corrente. */
const ACTIVE_SECTION_THRESHOLD = 0.3;
/** Margine entro cui lo scroll si considera esaurito, per gli arrotondamenti a frazioni di pixel. */
const SCROLL_END_TOLERANCE = 2;

@Component({
  selector: "mvp-root",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ButtonComponent, LucideArrowUp, LucideMoon, LucideSun, RouterOutlet],
  template: `
    <div class="shell">
      <aside class="sidebar" aria-label="Navigazione applicativa">
        <div class="brand">
          <img src="eggon-logo.png" alt="Eggon" />
        </div>
        <nav class="nav">
          @for (group of navGroups; track group.title) {
            <div class="section" role="group" [attr.aria-labelledby]="'nav-group-' + $index">
              <p class="sectionTitle" [id]="'nav-group-' + $index">{{ group.title }}</p>
              @for (item of group.items; track item.id) {
                <div class="itemGroup">
                  <a
                    class="item"
                    [class.active]="item.id === activeView()"
                    [href]="linkTo(item.id)"
                    [attr.aria-current]="item.id === activeView() ? 'page' : null"
                    (click)="onNavigate($event, item.id)"
                  >
                    <span>{{ item.label }}</span>
                  </a>
                  @for (child of item.children ?? []; track child.targetId) {
                    <a
                      class="subitem"
                      [class.subitemActive]="item.id === activeView() && child.targetId === activeChildId()"
                      [href]="linkTo(item.id, child.targetId)"
                      [attr.aria-current]="item.id === activeView() && child.targetId === activeChildId() ? 'location' : null"
                      (click)="onNavigate($event, item.id, child.targetId)"
                    >
                      {{ child.label }}
                    </a>
                  }
                </div>
              }
            </div>
          }
        </nav>
      </aside>

      <div class="workspace">
        <header class="header" id="mvp-topbar">
          <div>
            <p class="eyebrow">NEXUM</p>
            <h1>{{ title() }}</h1>
          </div>
          <button
            class="themeToggle"
            type="button"
            [attr.aria-label]="theme.theme() === 'dark' ? 'Attiva tema chiaro' : 'Attiva tema scuro'"
            [attr.aria-pressed]="theme.theme() === 'dark'"
            (click)="theme.toggle()"
          >
            <svg lucideSun aria-hidden="true"></svg>
            <svg lucideMoon aria-hidden="true"></svg>
          </button>
        </header>

        <main class="main">
          <router-outlet />
        </main>

        <button
          mvpButton
          class="backToTop"
          [class.isVisible]="showBackToTop()"
          variant="icon"
          type="button"
          aria-label="Torna su"
          (click)="scrollToTop()"
        >
          <svg lucideArrowUp aria-hidden="true"></svg>
        </button>
      </div>
    </div>
  `,
  styleUrls: [
    "./layout/app-shell/app-shell.css",
    "./layout/sidebar-nav/sidebar-nav.css",
    "./layout/page-header/page-header.css",
    "./app.css"
  ]
})
export class AppComponent {
  protected readonly navGroups = mvpNavGroups;
  protected readonly activeView = signal<MvpView>("overview");
  protected readonly activeChildId = signal<string | null>(null);
  /** Il pulsante "torna su" compare solo dopo aver scrollato la pagina. */
  protected readonly showBackToTop = signal(false);
  protected readonly title = computed(() => mvpViewTitles[this.activeView()]);
  protected readonly theme = inject(ThemeService);
  private readonly router = inject(Router);
  private readonly store = inject(MvpStateStore);
  private readonly activeChildIds = computed(
    () =>
      this.navGroups
        .flatMap((group) => group.items)
        .find((item) => item.id === this.activeView())
        ?.children?.map((child) => child.targetId) ?? []
  );

  constructor() {
    this.store.loadOnce();
    this.syncActiveView(this.router.url);

    this.router.events
      .pipe(
        filter((event): event is NavigationEnd => event instanceof NavigationEnd),
        takeUntilDestroyed()
      )
      .subscribe((event) => this.syncActiveView(event.urlAfterRedirects));

    // Listener passivo con throttling per non gravare sul rendering.
    fromEvent(window, "scroll", { passive: true })
      .pipe(auditTime(120), takeUntilDestroyed())
      .subscribe(() => {
        this.updateBackToTopVisibility();
        this.updateActiveChild();
      });
    this.updateBackToTopVisibility();

    // Cambio di vista: si riparte dalla prima sezione e si rilegge la posizione
    // al frame successivo, quando le sezioni della nuova pagina sono montate.
    effect((onCleanup) => {
      const ids = this.activeChildIds();
      this.activeChildId.set(ids[0] ?? null);

      const frameId = window.requestAnimationFrame(() => this.updateActiveChild(ids));
      onCleanup(() => window.cancelAnimationFrame(frameId));
    });
  }

  /** Indirizzo reale della voce, cosi' il collegamento e' apribile in una nuova scheda. */
  protected linkTo(view: MvpView, targetId?: string): string {
    return targetId === undefined ? `/${view}` : `/${view}#${targetId}`;
  }

  /** Il click semplice resta gestito dal router (niente ricaricamento SPA); click modificato o non primario va lasciato al browser. */
  protected onNavigate(event: MouseEvent, view: MvpView, targetId?: string): void {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
      return;
    }

    event.preventDefault();
    this.navigate(view, targetId);
  }

  protected navigate(view: MvpView, targetId?: string): void {
    void this.router.navigate([view]).then(() => this.scrollTo(targetId ?? "mvp-topbar"));
  }

  protected scrollToTop(): void {
    this.scrollTo("mvp-topbar");
  }

  private updateBackToTopVisibility(): void {
    this.showBackToTop.set(window.scrollY > 320);
  }

  /**
   * Sezione corrente nella sidebar: banda fissa a un quinto del viewport, non IntersectionObserver.
   * Regola: "l'ultima sezione il cui inizio e' gia' passato", che a fine pagina si ferma comunque sull'ultima.
   */
  private updateActiveChild(knownIds?: readonly string[]): void {
    const ids = knownIds ?? this.activeChildIds();
    const positioned = ids
      .map((id) => ({ id, element: document.getElementById(id) }))
      .filter((entry): entry is { id: string; element: HTMLElement } => entry.element !== null);

    if (positioned.length === 0) {
      return;
    }

    const documentHeight = document.documentElement.scrollHeight;
    const atBottom = window.scrollY + window.innerHeight >= documentHeight - SCROLL_END_TOLERANCE;

    if (atBottom) {
      this.activeChildId.set(positioned[positioned.length - 1].id);
      return;
    }

    const threshold = window.innerHeight * ACTIVE_SECTION_THRESHOLD;
    const passed = positioned.filter((entry) => entry.element.getBoundingClientRect().top <= threshold);

    this.activeChildId.set((passed[passed.length - 1] ?? positioned[0]).id);
  }

  private syncActiveView(url: string): void {
    const firstSegment = url.split("?")[0]?.split("#")[0]?.split("/").filter(Boolean)[0];
    this.activeView.set(isMvpView(firstSegment) ? firstSegment : "overview");
  }

  private scrollTo(elementId: string): void {
    window.requestAnimationFrame(() => {
      document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
}
