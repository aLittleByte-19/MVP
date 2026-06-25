import { ChangeDetectionStrategy, Component, computed, input } from "@angular/core";
import { type FormControl, ReactiveFormsModule } from "@angular/forms";

@Component({
  selector: "poc-select-field",
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ReactiveFormsModule],
  template: `
    <label class="field" [htmlFor]="fieldId()">
      <span>{{ label() }}</span>
      <select [id]="fieldId()" [formControl]="control()">
        @for (option of options(); track option) {
          <option [value]="option">{{ option }}</option>
        }
      </select>
    </label>
  `,
  styleUrl: "./select-field.css"
})
export class SelectFieldComponent {
  readonly label = input.required<string>();
  readonly options = input.required<readonly string[]>();
  readonly control = input.required<FormControl<string>>();
  readonly id = input<string>();

  protected readonly fieldId = computed(
    () => this.id() ?? `field-${this.label().toLowerCase().replaceAll(" ", "-")}`
  );
}
