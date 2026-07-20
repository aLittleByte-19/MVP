import { Component, EventEmitter, Input, Output } from '@angular/core'

@Component({
  selector: 'mvp-star-rating',
  imports: [],
  templateUrl: './star-rating.html',
  styleUrl: './star-rating.css',
})
export class StarRating {
  @Input() rating: number = 0;
  @Input() disabled: boolean = false;
  @Output() rated = new EventEmitter<number>();

  protected hoverState: number = 0;

  protected rate(star: number): void {
    if (this.disabled) return;
    this.rating = star;
    this.rated.emit(star);
  }
}


