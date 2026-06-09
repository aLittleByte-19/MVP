import { useEffect, useState } from "react";

/**
 * Tracks which of the given section ids is currently in view and returns it.
 * Used by the sidebar to highlight the subitem matching the scroll position.
 *
 * Uses an IntersectionObserver with an activation band near the top of the
 * viewport (15%–45%). The active id is the first section, in document order,
 * currently crossing that band, so highlighting advances smoothly section by
 * section instead of jumping. Missing ids and environments without
 * IntersectionObserver (e.g. jsdom) degrade gracefully.
 */
export function useScrollSpy(ids: string[]): string | null {
  const key = ids.join("|");
  const [activeId, setActiveId] = useState<string | null>(ids[0] ?? null);

  useEffect(() => {
    const elements = ids
      .map((id) => document.getElementById(id))
      .filter((element): element is HTMLElement => element !== null);

    if (elements.length === 0) {
      setActiveId(null);

      return;
    }

    setActiveId(elements[0].id);

    if (typeof IntersectionObserver === "undefined") {
      return;
    }

    const visible = new Set<string>();

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            visible.add(entry.target.id);
          } else {
            visible.delete(entry.target.id);
          }
        }

        const firstVisible = ids.find((id) => visible.has(id));

        if (firstVisible) {
          setActiveId(firstVisible);
        }
      },
      { rootMargin: "-15% 0px -55% 0px", threshold: 0 },
    );

    elements.forEach((element) => observer.observe(element));

    return () => observer.disconnect();
    // `key` captures changes to the id list (e.g. when the active view changes).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  return activeId;
}
