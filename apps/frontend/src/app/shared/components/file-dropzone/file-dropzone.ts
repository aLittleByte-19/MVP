import { ChangeDetectionStrategy, Component, input, output } from "@angular/core";
import { LucideUploadCloud } from "@lucide/angular";

@Component({
  selector: "poc-file-dropzone",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [LucideUploadCloud],
  template: `
    <label class="dropzone">
      <svg lucideUploadCloud aria-hidden="true"></svg>
      <span>{{ label() }}</span>
      <input type="file" accept="application/pdf" [disabled]="disabled()" (change)="onChange($event)" />
    </label>
  `,
  styleUrl: "./file-dropzone.css"
})
export class FileDropzoneComponent {
  readonly label = input.required<string>();
  readonly disabled = input<boolean>(false);
  readonly fileSelected = output<File>();

  protected onChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    if (file) {
      this.fileSelected.emit(file);
      target.value = "";
    }
  }
}
