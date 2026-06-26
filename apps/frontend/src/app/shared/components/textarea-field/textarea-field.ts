import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { type FormControl, ReactiveFormsModule } from "@angular/forms";

@Component({
  selector: "poc-textarea-field",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule],
  template: `
    <label class="field" [htmlFor]="fieldId()">
      <span>{{ label() }}</span>
      <textarea
        [id]="fieldId()"
        [formControl]="control()"
        [rows]="rows()"
        [attr.placeholder]="placeholder()"
      ></textarea>
    </label>
  `,
  styleUrl: "./textarea-field.css"
})
export class TextAreaFieldComponent {
  readonly label = input.required<string>();
  readonly control = input.required<FormControl<string>>();
  readonly rows = input<number>(4);
  readonly placeholder = input<string>();
  readonly id = input<string>();

  protected readonly fieldId = computed(
    () => this.id() ?? `field-${this.label().toLowerCase().replaceAll(" ", "-")}`
  );
}
