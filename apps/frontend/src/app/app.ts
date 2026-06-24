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
  isPocView,
  pocNavGroups,
  pocViewTitles,
  type PocView
} from "./core/navigation/app-views";
import { PocStateStore } from "./core/state/poc-state.store";
import { ThemeService } from "./core/theme/theme.service";

@Component({
  selector: "poc-root",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ButtonComponent, LucideArrowUp, LucideMoon, LucideSun, RouterOutlet],
  template: `
    <div class="shell">
      <aside class="sidebar" aria-label="Navigazione applicativa">
        <div class="brand">
          <img src="alittlebyte-logo.png" alt="Alittlebyte" />
        </div>
        <nav class="nav">
          @for (group of navGroups; track group.title) {
            <div class="section">
              <p class="sectionTitle">{{ group.title }}</p>
              @for (item of group.items; track item.id) {
                <div class="itemGroup">
                  <button
                    class="item"
                    [class.active]="item.id === activeView()"
                    type="button"
                    [attr.aria-current]="item.id === activeView() ? 'page' : null"
                    (click)="navigate(item.id)"
                  >
                    <span>{{ item.label }}</span>
                  </button>
                  @for (child of item.children ?? []; track child.targetId) {
                    <button
                      class="subitem"
                      [class.subitemActive]="item.id === activeView() && child.targetId === activeChildId()"
                      type="button"
                      [attr.aria-current]="item.id === activeView() && child.targetId === activeChildId() ? 'location' : null"
                      (click)="navigate(item.id, child.targetId)"
                    >
                      {{ child.label }}
                    </button>
                  }
                </div>
              }
            </div>
          }
        </nav>
      </aside>

      <div class="workspace">
        <header class="header" id="poc-topbar">
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
          pocButton
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
  protected readonly navGroups = pocNavGroups;
  protected readonly activeView = signal<PocView>("overview");
  protected readonly activeChildId = signal<string | null>(null);
  /** Il pulsante "torna su" compare solo dopo aver scrollato la pagina. */
  protected readonly showBackToTop = signal(false);
  protected readonly title = computed(() => pocViewTitles[this.activeView()]);
  protected readonly theme = inject(ThemeService);
  private readonly router = inject(Router);
  private readonly store = inject(PocStateStore);
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

    // Il pulsante "torna su" e' utile solo quando c'e' contenuto sopra la
    // viewport: lo si mostra oltre una soglia di scroll (listener passivo,
    // throttling per non gravare sul rendering).
    fromEvent(window, "scroll", { passive: true })
      .pipe(auditTime(120), takeUntilDestroyed())
      .subscribe(() => this.updateBackToTopVisibility());
    this.updateBackToTopVisibility();

    effect((onCleanup) => {
      const ids = this.activeChildIds();

      if (typeof IntersectionObserver !== "function" || ids.length === 0) {
        this.activeChildId.set(ids[0] ?? null);
        return;
      }

      let observer: IntersectionObserver | null = null;
      const frameId = window.requestAnimationFrame(() => {
        observer = new IntersectionObserver(
          (entries) => {
            const visible = entries
              .filter((entry) => entry.isIntersecting)
              .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];

            if (visible?.target.id) {
              this.activeChildId.set(visible.target.id);
            }
          },
          { rootMargin: "-18% 0px -65% 0px", threshold: 0.01 }
        );

        for (const id of ids) {
          const element = document.getElementById(id);

          if (element) {
            observer.observe(element);
          }
        }
      });

      onCleanup(() => {
        window.cancelAnimationFrame(frameId);
        observer?.disconnect();
      });
    });
  }

  protected navigate(view: PocView, targetId?: string): void {
    void this.router.navigate([view]).then(() => this.scrollTo(targetId ?? "poc-topbar"));
  }

  protected scrollToTop(): void {
    this.scrollTo("poc-topbar");
  }

  private updateBackToTopVisibility(): void {
    this.showBackToTop.set(window.scrollY > 320);
  }

  private syncActiveView(url: string): void {
    const firstSegment = url.split("?")[0]?.split("#")[0]?.split("/").filter(Boolean)[0];
    this.activeView.set(isPocView(firstSegment) ? firstSegment : "overview");
  }

  private scrollTo(elementId: string): void {
    window.requestAnimationFrame(() => {
      document.getElementById(elementId)?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }
}
